# mppsolar2mqtt
MPPSolar / Voltronic / Infinisolar hybrid inverter integration to Homeassistant via MQTT
(Protocol version 17 only !)

This script will read data from an inverter (e.g. MPPSolar mpi10k) and send to Homeassistant
via MQTT. The inverter needs to connected via an Ethernet to RS232 bridge. This way it's easy
to connect to the inverter by Solarpower software and change parameters without unplugging
anything. Just stop the script and run Solarpower via a virtual COM port.
