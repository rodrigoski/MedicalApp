# Desacoplamiento: el patron Outbox

## 1. El problema

Al reservar una cita hay que avisar al paciente. La pregunta no es "como mando
el aviso", sino **que pasa si el aviso falla**.

El enfoque intuitivo — guardar la cita y, a continuacion, llamar por HTTP al
servicio de notificaciones — falla de tres maneras, y las tres en produccion:

1. **Aviso perdido.** La cita se guarda, la llamada HTTP da timeout, el aviso
   nunca sale. Si nadie se da cuenta, el paciente cree que tiene cita y no viene.
2. **Fallo ajeno tumba el sistema.** El servicio de notificaciones esta caido.
   La reserva, que ya es valida y pertenece al paciente, falla tambien. Un
   problema de un servicio opcional se convierte en caida del servicio principal.
3. **Aviso fantasma.** La llamada HTTP tiene exito, y un instante despues la
   transaccion de la cita revierte. El paciente recibe un aviso de una cita que no
   existe.

Ninguno de los tres casos es exotico. Todos ocurren en la primera semana de un
sistema en produccion.

---

## 2. La solucion: outbox transaccional

La observacion clave: **el aviso no es un efecto secundario de guardar la cita,
es un hecho que ocurre en el mismo instante**. Se producen juntos, o no se
produce ninguno.

```
            una sola transaccion
   +------------------------------------------+
   |  INSERT INTO appointments (...)           |
   |  INSERT INTO domain_events (...)   <-----+-- el evento nace ligado
   +------------------------------------------+   a la cita
                    |
                    |  AFTER COMMIT
                    v
       [proceso aparte] -> POST /api/v1/events
```

### Que resuelve

| Problema | Como lo resuelve |
|---|---|
| Aviso perdido | El evento esta en la base, no en memoria. Un comando reintenta hasta entregarlo. |
| El servicio caido tumba la reserva | La entrega ocurre **despues** del commit, en un proceso distinto. La reserva no depende de nadie. |
| Aviso fantasma | Si la transaccion revierte, no hay evento. Es imposible que exista. |
| Llamada de red dentro de una transaccion | Desaparecio: la publicacion ocurre fuera. |

### Lo que sigue siendo dificil

La entrega es **al menos una vez**, no exactamente una vez. Si el microservicio
procesa el evento y la respuesta se pierde por el camino, Laravel reintentara y
el mismo evento llegara dos veces. Es una limitacion inherente al modelo, y se resuelve en el receptor, no aqui.

---

## 3. Idempotencia: que hacer con el duplicado

Es el problema central del patron. Sin su resolucion, el modelo "al menos una
vez" produce dos avisos al mismo paciente, que en un sistema de citas se traduce
en una queja real.

### La garantia esta en el receptor

```sql
CREATE TABLE notifications (
    id         uuid PRIMARY KEY,
    event_id   text NOT NULL,
    ...
    CONSTRAINT notifications_event_id_unique UNIQUE (event_id)
);
```

Tres mecanismos, en orden:

1. **Comprobacion previa** en el caso de uso: `find_by_event_id`. Resuelve el
   caso normal (el reintento llega segundos despues) sin tocar la base de forma
   especial.
2. **Restriccion `UNIQUE`**: resuelve la carrera. Dos workers simultaneos que
   pasan la comprobacion previa no pueden insertar los dos.
3. **Traduccion del error**: si la restriccion salta, el receptor responde `409` y
   el productor reintenta; para entonces la lectura ya devuelve la notificacion
   existente.

**Por que el orden importa.** Si se invirtiera (confiar solo en la comprobacion
previa), la ventana entre el `SELECT` y el `INSERT` permite el duplicado. Si se
invirtiera el otro extremo (confiar solo en el indice), cada reintento costaria un
error de integridad. La comprobacion previa optimiza; la restriccion garantiza.

### Codigo de respuesta: 200, no 409

Un evento duplicado responde `200` con la notificacion existente, y `duplicate:
true` en el cuerpo. No es un error: para el productor la operacion fue
satisfactoria, el evento quedo aceptado y no debe reintentarse mas. Responder
409 obligaria al productor a reintentar un evento que ya se proceso, y terminaria
en un bucle.

---

## 4. Reintentos

En el receptor, un fallo del canal no es un error del sistema: es un problema
temporal de la pasarela de SMS. La respuesta correcta no es propagar la
excepcion, es reprogramar.

```
  PENDING --intento--> SENT
     |
     | fallo
     v
   FAILED --intento (30s)--> SENT
     |
     | fallo
     v
   FAILED --intento (60s)--> ...
     |
     | intentos agotados
     v
    DEAD  (revision manual)
```

### Backoff exponencial

```python
delay = min(cap, base * 2 ** (attempts - 1))   # 30s, 60s, 120s, 240s...
```

Razon: si la pasarela esta caida, reintentar cada 5 segundos solo empeora la
caida y agota el presupuesto de la plataforma externa. El backoff deja que se
recupere.

El tope importa igual que el crecimiento: sin el, `2 ** 20` segundos son veinte
anos. Ninguna notificacion merece esperar veinte anos.

### Estado `DEAD`

Agotar los intentos **no** es un fallo silencioso. La notificacion pasa a `dead`,
deja de reintentarse y aparece en `GET /api/v1/notifications?status=dead`.

Un reintento infinito esconde el problema: la cola "parece" funcionar, el
indicador de exito esta en verde y los avisos simplemente no salen. Un estado
terminal explicito hace visible exactamente lo que no se entrego.

### Contar el intento ANTES de enviar

El estado se guarda antes de invocar al canal, no despues. Si el proceso muere
entre el envio y el guardado, el contador ya refleja un intento real.

Es la eleccion conservadora: se puede contar un envio que no ocurrio (el proceso
murio antes), pero nunca se pierde la cuenta de uno que si ocurrio. Contar de mas
produce un reintento de mas; contar de menos produce una perdida silenciosa.

---

## 5. Frontera de cada capa

```
  +----------------+      +------------------+      +------------------+
  |  Laravel       |      |  PostgreSQL      |      |  FastAPI         |
  |                |      |                  |      |                  |
  |  EventPublisher|---->| domain_events    |      |  ReceiveEvent    |
  |  (no sabe que  |      | (outbox)         |      |  Service         |
  |   pasa despues)|      +------------------+      |                  |
  +-------+--------+                                |  DeliverPending  |
          |                                         |  Service         |
          |  HTTP + API key                         +--------+---------+
          +------------------------------------------------+
```

### Laravel no sabe que hay reintentos

`EventPublisher` entrega y, si falla, devuelve `false`. No reintenta, no duerme,
no guarda estado. La reentrega la gestiona `events:dispatch` y el worker de cola.
Si el servicio esta caido una hora, la responsabilidad de reintentar es del
proceso, no de la peticion que reservo la cita.

### FastAPI no sabe de donde viene el evento

`ReceiveEventService` recibe un `EventEnvelope` y no sabe si lo mando Laravel,
un reintento, una carga manual o un test. La idempotencia esta en el receptor
porque es el unico sitio que puede garantizar "este evento ya se proceso".

---

## 6. Contrato entre los dos servicios

```http
POST /api/v1/events
Authorization: Bearer <API_KEY>
Content-Type: application/json
X-Request-Id: 0f2c1d3e-...
```

```json
{
  "event_id": "6f1d2c3a-9b8e-4f21-8a7b-0c1d2e3f4a5b",
  "event_type": "appointment.created",
  "aggregate_type": "appointment",
  "aggregate_id": 812,
  "occurred_at": "2025-01-07T15:00:00+00:00",
  "payload": { "patient_id": 44, "doctor_id": 3 }
}
```

| Respuesta | Significado | Accion del productor |
|---|---|---|
| `201` | Evento nuevo, notificacion creada | Marcar entregado |
| `200` | Duplicado, ya existia | Marcar entregado |
| `409` | Carrera entre entregas | Reintentar |
| `401` | Credencial incorrecta | Reintentar (se loguea) |
| `422` | Evento mal formado | **No** reintentar: se descartara con traza |
| `500` | Fallo del servicio | Reintentar con backoff |

La distincion entre `422` y `500` es la que evita que un evento defectuoso
bloquee la cola reintentandolo para siempre.

---

## 7. Lo que este diseno NO resuelve

Ser explicito sobre los limites evita tomar decisiones futuras creyendo que ya
estan cubiertas:

1. **Exactly-once entre servicios.** No existe con HTTP. Se tiene "at-least-once"
   mas idempotencia en el receptor, que es el maximo alcanzable sin transaccion
   distribuida. Un SMS duplicado es posible si el canal externo no es idempotente.
2. **Consistencia entre las dos bases.** `clinic_app` y
   `clinic_notifications` no comparten transaccion. Una notificacion puede existir
   para una cita que se revirtio: por eso el evento nace **dentro** de la
   transaccion de la cita, y una cita revertida no deja evento.
3. **Orden de los eventos.** No hay garantia. Si una cita se crea y se cancela
   muy cerca, el receptor puede procesarlos en orden inverso. Para este dominio
   no importa; para un flujo que dependa del orden habria que incluir un
   numero de secuencia en el evento y descartar los obsoletos.
4. **Volumen alto.** Una tabla `domain_events` que se poda por dias aguanta
   cientos de miles de filas. Si el volumen creciera, el punto de migracion es
   Kafka o una cola dedicada, conservando la idempotencia en el consumidor.

---

## 8. Resumen de decisiones

| Decision | Alternativa descartada | Motivo |
|---|---|---|
| Outbox en PostgreSQL | Cola de mensajes | Un componente menos que operar; y la outbox ya es transaccional con el negocio |
| Publicacion despues del commit | Publicacion durante la transaccion | No se mantiene una transaccion SQL abierta por una llamada de red |
| `event_id` `UNIQUE` en el receptor | Confiar en la comprobacion previa | La comprobacion previa tiene una carrera; el indice no |
| `200` para duplicados | `409` para duplicados | El productor debe dejar de reintentar un evento ya procesado |
| Backoff exponencial con tope | Reintentos fijos | Un destino caido no se arregla con mas reintentos |
| Estado `dead` | Reintento infinito | Un fallo permanente debe ser visible, no silencioso |
| Intento contado antes del envio | Contar despues | Nunca subcontar intentos; sobrecontar es recuperable |
| Base de datos separada | Misma base | Aislar el esquema permite migrar un servicio sin tocar el otro |
