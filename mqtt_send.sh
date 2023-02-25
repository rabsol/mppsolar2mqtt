#!/bin/bash
#
# Simple script to end MQTT data

MQTT_SERVER="192.168.178.11"
MQTT_PORT="1883"
MQTT_USERNAME="someuser"
MQTT_PASSWORD="somepwd"

MQTT_TOPIC="homeassistant"
MQTT_DEVICENAME="mpi10k"
MQTT_CLIENTID="mpi10k_bd8041d0cdf131a6ba4e5b3360b8bc5a"
MQTT_PREFIX="${MQTT_TOPIC}/sensor/${MQTT_DEVICENAME}"

mosquitto_pub \
        -r  -h $MQTT_SERVER  -p $MQTT_PORT  -u "$MQTT_USERNAME"  -P "$MQTT_PASSWORD"  -i "$MQTT_CLIENTID" \
        -t "${MQTT_PREFIX}/${1}/state" \
        -m "${2}"

