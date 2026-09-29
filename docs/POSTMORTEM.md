# Post-mortem

Errores encontrados durante el desarrollo, por que ocurrieron y como se
corrigieron. Se documentan los que quedaron resueltos y los que siguen abiertos,
porque un post-mortem que solo lista éxitos no sirve para nada.

---

## 1. Comparacion de jornada contra la zona equivocada

**Sintoma:** una cita confirmada a las 8:00 hora local de Bogota (13:00 UTC) era
rechazada con `outside_hours`. El mismo horario, introducido como `13:00Z`, si se
aceptaba.

**Causa:** la politica comparaba la hora UTC de la cita (13) contra la jornada
definida en hora local (8-18).

**Por que no se detecto pronto:** el seed y las primeras pruebas usaban horarios
que coincidian en ambas zonas. Solo aparecia con una cita real de la clinica, que
nunca es a las 8 UTC.

**Correccion:** la politica recibe `clinicTimezone` y convierte el instante a hora
local antes de evaluar la jornada. La comparacion sigue siendo en UTC para
orden y para traslapes; solo la regla de "hora de trabajo" se evalua en local.

```php
$local = $start->setTimezone($this->clinicTimezone);
if ($local->hour < $startHour || $local->hour >= $endHour) { ... }
```

**Prueba que lo cubre:** `AppointmentOverlapPolicyTest` con una zona distinta de
UTC, y `test_la_jornada_se_evalua_en_la_hora_local_de_la_clinica`.

**Leccion:** una zona horaria no es decorativa. Los intervalos semiabiertos ya
estaban bien; lo que faltaba era declarar **en que zona** se evalua cada regla.

---

## 2. El limite diario se calculaba en UTC

**Sintoma:** un medico con 24 citas validas de 08:00 a 18:00 hora local
(13:00-23:00 UTC) podia recibir una cita mas a las 23:30 UTC, que en hora local
ya es del dia siguiente.

**Causa:** el limite diario se evaluaba contra un rango UTC de "hoy" calculado
con el dia UTC, que en Bogota empieza a las 19:00 del dia anterior.

**Correccion:** `AppointmentService::clinicDayWindow()` construye la ventana
`[00:00, 24:00)` en hora local y la convierte a UTC. El repositorio paso de
`countActiveForDoctorOnDay($day)` a `countActiveForDoctorBetween($from, $to)`.

Ademas se paso de `< now` a `<= to` en el borde: con `<`, la ultima cita del dia
(que termina exactamente a las 23:59) no se contaba, y el limite nunca se
alcanzaba.

**Prueba que lo cubre:** `AppointmentApiTest::test_limita_el_numero_de_citas_diarias`.

**Leccion:** un "dia" no es un dia UTC. Es un dia de la clinica, que tiene una
zona. Y un rango inclusivo necesita ambos bordes, no solo el izquierdo.

---

## 3. La disponibilidad mostraba huecos fuera de jornada

**Sintoma:** la API de disponibilidad ofrecia huecos a las 03:00 y a las 21:00
hora local para un medico de 08:00 a 18:00.

**Causa:** `AvailabilityService` calculaba la ventana del dia en UTC, en lugar de
en la zona de la clinica. Con `America/Bogota`, la ventana UTC empezaba a las
19:00 del dia anterior.

**Correccion:** la ventana se construye en hora local y se convierte a UTC antes
de consultar y de escribir. Se anadio ademas el salto de domingo, que antes se
comprobaba en UTC y por tanto descartaba el dia equivocado.

**Prueba que lo cubre:** `AppointmentApiTest::test_todo_hueco_ofrecido_es_agendable`
— el nombre dice lo que verifica: **ofrecer un hueco que al reservarlo falla es
el peor fallo posible de este endpoint**, porque el usuario solo descubre el
problema despues de elegir hora.

**Leccion:** la prueba de un endpoint de disponibilidad no es "devuelve huecos",
sino "todo lo que devuelve es utilizable".

---

## 4. La reprogramacion fallaba contra si misma

**Sintoma:** `POST /appointments/{id}/reschedule` con el mismo horario
devolvia `doctor_busy` en lugar de reprogramar.

**Causa:** la comprobacion de traslape incluia la cita que se estaba
reprogramando. Se traslape consigo misma.

**Correccion:** el repositorio acepta un identificador de cita a excluir, y la
politica no la considera en las consultas de traslape ni en el limite diario.

**Por que se encanta el bug:** el caso "mismo horario" parece absurdo, pero es
exactamente lo que hace un paciente que cancela y vuelve a agendar. Y el caso
habitual (mover la cita a otro hueco) funcionaba, asi que las pruebas normales no
lo detectaban.

**Leccion:** las pruebas de los endpoints de reprogramacion deben incluir el caso
"sin cambio de horario". Es el caso raro y es el que se rompe.

---

## 5. El seed generaba citas fuera de jornada

**Sintoma:** `db:seed` fallaba con `outside_hours` en cuanto el reloj
real caia fuera de 08:00-18:00 hora local.

**Causa:** el seeder usaba la hora actual del sistema como base y anadia offsets.

**Correccion:** `DemoDataSeeder` recibe `ClockInterface` y calcula un
"proximo dia laborable" a partir del instante inyectado, respetando el domingo, y
coloca cada cita **a la hora de apertura de su propio medico**, en hora local
convertida a UTC.

**Correccion secundaria:** el seeder ahora intenta deliberadamente un traslape y
exige que sea rechazado. Antes, un seed que colaba dos citas traslapadas habria
fallado en la restriccion de la base de datos, sin mensaje util.

**Prueba que lo cubre:** `DemoDataSeederTest`, con cuatro escenarios: conteos,
rechazo del traslape, jornada respetada e idempotencia tras purgar.

**Leccion:** un seeder que depende de la hora del sistema es un seeder que falla
un dia de cada siete. Inyectarle el reloj lo hace determinista y, de paso,
testeable.

---

## 6. `DocumentId::normalise()` no existia

**Sintoma:** error fatal en cada alta de paciente.

**Causa:** el Form Request normalizaba el documento antes de validar, para que
`"cc-1010"` y `"CC1010"` se reconocieran como el mismo. Pero la clase `DocumentId`
solo exponia `from()`, que ademas lanza excepcion cuando el valor es invalido. La
normalizacion vivia en `InvalidDocumentId::normalise()`, como metodo de una
excepcion, y no habia forma de llamarla desde la capa HTTP.

**Por que es importante y no un simple typo:** sin normalizar antes de validar
unicidad, la consulta de duplicados compararia `"cc-1010"` contra `"CC1010"`, no
encontraria nada y **insertaria dos pacientes con el mismo documento**. El bug
pasaria desapercibido en pruebas hechas con documentos ya en mayusculas.

**Correccion:** `DocumentId::normalise()` publico, que delega en
`InvalidDocumentId::normalise()`, y `from()` lo usa. La capa HTTP normaliza
primero, valida despues.

**Leccion:** en un sistema donde la identidad se normaliza, el orden
"normalizar -> validar unicidad" no es un detalle. Es la garantia de unicidad.

---

## 7. `UpdatePatientRequest` ignoraba las alergias

**Sintoma:** `PATCH /patients/{id}` con `{"allergies": ["Latex"]}` devolvia 200
y no cambiaba nada.

**Causa:** el DTO `UpdatePatientData` soportaba el campo, pero la Form Request no
lo declaraba en sus reglas. Laravel solo pasa a `validated()` lo que esta
declarado, asi que el DTO recibia `null` y el servicio, correctamente, no
tocaba nada.

**Por que es peligroso:** la respuesta 200 afirma que la operacion se realizo. Un
clinico que actualiza la lista de alergias de un paciente y recibe 200 creera que
el registro esta actualizado, cuando no. Un dato clinico perdido con exito
reportado es peor que un error visible.

**Correccion:** `'allergies' => ['sometimes', 'nullable', 'array', 'max:20']` con
`allergies.*` validado elemento a elemento.

`sometimes` y no `required`: con `required`, un PATCH que solo cambia el nombre
fallaria por no incluir las alergias. Y `nullable` mas `array` permite el caso
"borrar todas las alergias" con una lista vacia, que es distinto de "no tocar el
campo".

**Prueba que lo cubre:** `PatientApiTest::test_actualiza_los_datos_de_un_paciente`.

**Leccion:** un DTO que soporta un campo y un Form Request que no lo declara son
dos contratos distintos. El 200 silencioso es el peor resultado posible: mas que
un 422, que al menos obliga a mirar.

---

## 8. La busqueda de pacientes fallaba en SQLite

**Sintoma:** `GET /patients?search=ana` no encontraba nada en las pruebas, y
funcionaba en PostgreSQL.

**Causa:** la consulta usaba `ILIKE`, que es especifico de PostgreSQL. SQLite no
lo reconoce y las pruebas de integracion fallaban.

**Correccion:** consulta sensible al motor: `ILIKE` en PostgreSQL,
`LOWER(columna) LIKE LOWER(?)` en SQLite. El patron general (comparacion sin
distincion de mayusculas) se mantiene igual.

**Leccion:** es tentador concluir "las pruebas fallan porque SQLite no es
PostgreSQL". La conclusion util es distinta: si una caracteristica aparece solo en
pruebas, o la consulta es dependiente del motor, o la prueba esta probando algo
distinto de lo que se Cree.

---

## 9. Se creo un canal de webhook sin estrategia de reintento

**Sintoma:** el primer borrador de `DeliverPendingService` capturaba la excepcion
del canal y continuaba, pero no guardaba ningun estado de fallo. La notificacion
quedaba `pending` para siempre y el proximo ciclo la reintentaba en bucle.

**Causa:** se escribio "manejo de errores" sin un modelo de estados. Un
`try/except` que registra y sigue no es manejo de errores, es ocultacion.

**Correccion:** ciclo de vida explicito con `PENDING`, `FAILED`, `SENT` y `DEAD`;
`RetryPolicy` calcula el backoff; el error se guarda en `last_error`; al agotar
los intentos la notificacion queda en `dead` y visible por API.

Ademas, el estado se guarda **antes** de invocar el canal, para que un proceso
muerto a mitad no subcuente los intentos.

**Leccion:** reintentar sin estado terminal no es resiliencia, es un bucle que
consume recursos mientras el problema sigue sin verse.

---

## 10. El entorno de desarrollo no tiene las herramientas del proyecto

**Sintoma:** `php`, `composer`, `docker`, `python` y `git` no estan instalados. No
se puede ejecutar la suite de pruebas, ni las migraciones, ni el arranque del
stack.

**Decision tomada:** no se reporta la suite como verde. Se hizo una revision
estatica completa de firmas, imports, constructores y llamadas cruzadas, y se
dejo constancia explicita de que la ejecucion no ocurrio.

Se corrigieron por revision estatica varios problemas que habrian hecho fallar la
suite en el primer `make test`: el metodo inexistente `DocumentId::normalise()`,
las reglas de alergias ausentes, las aserciones de paginación con el nombre de
clave equivocado (`current_page` en vez de `page`) y una prueba de jornada que
usaba una hora ya pasada y por tanto comprobaba otra regla.

**Leccion:** "no lo pude ejecutar" no es lo mismo que "funciona". Un entregable
que declara pruebas exitosas sin haberlas ejecutado es peor que uno que declara
lo que hizo y lo que no.

---

## 11. Menores, resueltos en caliente

| Problema | Causa | Correccion |
|---|---|---|
| `channels.py` y `channels/` en el mismo directorio | Un archivo y un paquete con el mismo nombre | Se elimino el modulo y se organizo el paquete |
| `deps.py` con `get_container` lanzando excepcion | Dependencia de FastAPI mal resuelta | Se reescribio con `Request` y `Depends` correctamente |
| Endpoint de listado que devolvia `[]` fijo | Metodo de repositorio inexistente | Se anadio `list_by_status` al repositorio y al puerto |
| `Raw` en el docblock de un test | Texto corrupto al escribir | Corregido |
| Import sin usar en un Form Request | Residuo de una reescritura | Eliminado |

---

## 12. Deuda tecnica consciente

Lo que queda pendiente, con el razonamiento de por que se acepto:

| Deuda | Por que se acepta | Cuando corregir |
|---|---|---|
| Sin HTTPS | El despliegue es local | Antes de exponer el sistema |
| Clave compartida en vez de usuarios y roles | El consumo es entre dos despliegues internos | Si el sistema se expone a Internet |
| Canal de notificacion en modo `log` | No hay pasarela de SMS disponible | Al integrar el proveedor real |
| Sin OpenTelemetry | El `request_id` ya permite correlacionar | Cuando haya mas de un servicio |
| Copias de seguridad sin automatizar | Fuera del alcance de la demostracion | Antes de usar datos reales |
| Una sola instancia del backend | Suficiente para la demostracion | Si se necesita capacidad |

Ninguna de estas deudas oculta un defecto: son limites conocidos y escritos. Un
sistema del que no se puede decir que le falta esto y aquello no es entendible ni
auditable.
