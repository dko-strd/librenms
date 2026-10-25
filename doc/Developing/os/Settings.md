# Optional OS Settings

This page describes the settings of the OS YAML files and of
`config.php`. All these settings are optional. Without a setting,
LibreNMS uses the global default.

### User override in config.php

A user can override these settings in `config.php`.

For example, this line sets a different icon for ios:

```php
$config['os']['ios']['icon'] = 'fuzzybunny';
```

### Counter display

Count sensors stored as COUNTER, DERIVE, DCOUNTER or DDERIVE normally display an
estimated rate. An OS definition can opt selected counter sensors into displaying
the cumulative value instead, without changing discovery, polling or RRD storage:

```yaml
sensor_counter_display:
    - { type: example-counter, index: ignored-counter.0, display: rate }
    - { type: example-counter, display: total }
```

`type` matches `sensor_type` exactly. Optional `index` matches `sensor_index`
exactly; omit it to match all indexes of that type. The first matching rule wins.
Unmatched sensors keep the existing rate display. `total` applies only to count
sensors with counter RRD types, so gauges and other sensor classes are unchanged.
Total values use whole numbers with locale-aware grouping when PHP Intl is
available, or comma grouping otherwise. Missing values remain missing.

Overview uses the standard sensor panel and splits an affected group into Current
values and Cumulative totals. Health labels opted-in values as cumulative totals.
A total is not a daily count and may reset. Sensor tables identify totals and omit
their adjacent rate graphs, including the expanded graph view. Counter RRD graphs
elsewhere still represent rates of change; opted-in graphs use a per-second axis
and omit alert threshold lines, because those limits apply to the raw cumulative
value. Port sensor panels label retained graphs as Rate of change. Stored RRD data,
threshold settings and alert evaluation are unchanged.

To override or disable the rules for one OS in `config.php`:

```php
$config['os']['example-os']['sensor_counter_display'] = [
    ['type' => 'example-counter', 'display' => 'total'],
];
// Restore the default rate display for all counters of that OS:
$config['os']['example-os']['sensor_counter_display'] = [];
```

### Ignoring Sensors

The configuration can filter out some sensors:

- Filter all 'current' sensors for Operating System 'vrp'.

```php
$config['os']['vrp']['disabled_sensors']['current'] = true;
```

- Filter all sensors with description matching regexp ```'/PEM Iout/'``` for Operating System iosxe.

```php
$config['os']['iosxe']['disabled_sensors_regex'][] = '/PEM Iout/';
```

- Filter all 'power' sensors with description matching regexp ```'/ Power [TR]x /'``` for Operating System iosxr.

```php
$config['os']['iosxr']['disabled_sensors_regex']['power'][] = '/ Power [TR]x /';
```

- Ignore all temperature sensors

```php
$config['disabled_sensors']['temperature'] = true;
```

- Filter all sensors matching with description regexp ```'/PEM Iout/'```.

```php
$config['disabled_sensors_regex'][] = '/PEM Iout/';
```

### Ignoring Interfaces

See also: [Global Ignoring Interfaces Config](../../Support/Configuration.md#interfaces-to-be-ignored)

> LibreNMS combines these settings with the global settings. Only
> `good_if` cancels a global setting.

```yaml
empty_ifdescr: false # allow empty ifDescr
bad_if: # ifDescr (substring, case insensitive)
    - lp0
bad_if_regexp: # ifDescr (regex, case insensitive)
    - "/^ng[0-9]+$/"
bad_ifname_regexp: # ifName (regex, case insensitive)
    - "/^xdsl_channel /"
bad_ifalias_regexp: # ifAlias (regex, case insensitive)
    - "/^vlan/"
bad_iftype: # ifType (substring)
    - sonet
good_if: # ignore all other bad_if settings ifDescr (substring, case insensitive)
    - virtual
bad_ifoperstatus # IfOperStatus (substring, case insensitive)
    - notPresent
```

### Controlling interface labels

By default, LibreNMS uses ifDescr as the label of a port. `ifname` and
`ifalias` override this default. Set only one of the two. The user
supplies ifAlias. `ifindex` adds the ifIndex to the end of the port
label.

```yaml
ifname: true
ifalias: true

ifindex: true
```

### Poller and Discovery Modules

You can enable and disable each discovery module and poller module for
one OS. The defaults are usually correct, so few changes are necessary.
You can enable and disable a module for one device in the web
interface. You can also do this for one OS or globally in `config.php`.
A poller module usually needs its discovery module.

Do not set these values to false in an OS definition, unless the module
makes the polling much worse. A module in the definition reduces the
control of the user.

```yaml
poller_modules:
    bgp-peers: true
discovery_modules:
    arp-table: false
```

### SNMP Settings

#### Disable snmpbulkwalk

Some devices have a bad SNMP implementation. They answer the faster
snmpbulkwalk poorly. This setting disables snmpbulkwalk. LibreNMS then
uses only snmpwalk for that OS.

```yaml
snmp_bulk: false
```

If only some OIDs fail with snmpbulkwalk, disable those OIDs. The value
must match the OID of the LibreNMS walk exactly. We prefer the
`MIB::oid` form, because it prevents a name collision.

```yaml
oids:
    no_bulk:
        - UCD-SNMP-MIB::laLoadInt
```

#### Limit the oids per snmpget

```yaml
snmp_max_oid: 8
```
#### Define SNMP repeater value by OS

Example ios:

```
lnms config:set ios.snmp.max_repeaters: 30
```

### Storage Settings

See also: [Global Storage Config](../../Support/Configuration.md#storage-configuration)

```yaml
ignore_mount_array: # exact match
    - /var/run
ignore_mount_string: # substring
    - run
ignore_mount_regexp: # regex
    - "/^\/var/"
```
