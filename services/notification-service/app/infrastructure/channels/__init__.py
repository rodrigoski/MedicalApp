"""Canales de salida (estrategia)."""

from app.infrastructure.channels.base import ChannelSenderBase
from app.infrastructure.channels.log import LogChannelSender
from app.infrastructure.channels.webhook import WebhookChannelSender

__all__ = ["ChannelSenderBase", "LogChannelSender", "WebhookChannelSender"]
