# Field network

Recommended physical layout:

```text
Neptune laptop --Ethernet--> router/switch --Wi-Fi--> tablets/phones
```

The router does not need an Internet/WAN connection. It only needs to provide the local LAN and DHCP.

For best reliability:

1. Connect the laptop to the router/switch by Ethernet rather than Wi-Fi.
2. Give the laptop a DHCP reservation in the router so its LAN IP does not change during the event.
3. Connect scouting tablets/phones only to that event LAN.
4. Bookmark the laptop address on every device before matches begin.
5. Run `sudo /var/www/neptune/scripts/field-server/field-check.sh` before scouting starts.
6. Run `sudo /var/www/neptune/scripts/field-server/backup-now.sh` at lunch and at the end of each competition day.

If the laptop hostname is changed to `neptune`, many phones/tablets can use `http://neptune.local/`. The LAN IP remains the universal fallback.
