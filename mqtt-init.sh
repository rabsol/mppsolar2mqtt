#!/bin/bash
#
# Simple script to register the MQTT topics when the container starts for the first time...

MQTT_SERVER="192.168.178.11"
MQTT_PORT="1883"
MQTT_USERNAME="someuser"
MQTT_PASSWORD="somepwd"

MQTT_TOPIC="homeassistant"
MQTT_DEVICENAME="mpi10k"
MQTT_CLIENTID="mpi10k_bd8041d0cdf131a6ba4e5b3360b8bc5a"
MQTT_PREFIX="${MQTT_TOPIC}/sensor/${MQTT_DEVICENAME}"

registerTopic () {
    mosquitto_pub \
        -r  -h $MQTT_SERVER  -p $MQTT_PORT  -u "$MQTT_USERNAME"  -P "$MQTT_PASSWORD"  -i "$MQTT_CLIENTID" \
        -t "${MQTT_PREFIX}/${1}/config" \
        -m "{
            \"name\": \"${1}\",
            \"unique_id\": \"${MQTT_DEVICENAME}_${1}\",
            \"object_id\": \"${MQTT_DEVICENAME}-${1}\",
            \"state_topic\": \"${MQTT_PREFIX}/${1}/state\",
            \"unit_of_measurement\": \"${2}\",
            \"device_class\": \"${4}\",
            \"state_class\": \"${5}\",
            \"icon\": \"mdi:${3}\",
            \"device\": {
              \"identifiers\": \"${MQTT_DEVICENAME}\",
              \"name\": \"${MQTT_DEVICENAME}\",
              \"manufacturer\": \"MPPSolar\",
              \"model\": \"MPI-10k\"}
        }"

}

# 1 = Power_On, 2 = Standby, 3 = Line, 4 = Battery, 5 = Fault, 6 = Power_Saving, 7 = Unknown
#---------------------------------------------------------------------------------------------
#             Name             Unit  Icon                device-class  stats-class
#---------------------------------------------------------------------------------------------
registerTopic "INV_mode"       "M"   "power-plug"           "power"       "measurement"
registerTopic "PV1_power"      "W"   "solar-panel-large"    "power"       "measurement"
registerTopic "PV2_power"      "W"   "solar-panel-large"    "power"       "measurement"
registerTopic "PV_total"       "W"   "solar-panel-large"    "power"       "measurement"
registerTopic "BAT_power"      "W"   "battery-outline"      "power"       "measurement"
registerTopic "BAT_amps"       "A"   "current-dc"           "current"     "measurement"
registerTopic "INV_temp"       "°C"  "coolant-temperature"  "temperature" "measurement"
registerTopic "AC_feed"        "W"   "power-plug"           "power"       "measurement"
registerTopic "KWh_today"      "kWh" "transmission-tower"   "energy"      "total_increasing"
registerTopic "KWh_total"      "kWh" "transmission-tower"   "energy"      "total_increasing"
registerTopic "AC_wh"          "Wh"  "power-plug"           "energy"      "total_increasing"

