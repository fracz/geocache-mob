# MOB Geocaches tool

[geocaching.fracz.com/mob](https://geocaching.fracz.com/mob/)

MOB Geocaches aim to gather certain number of people at specific place and
ask them to open the same URL with their mobile devices. As soon as the
system detects the required number of connected devices, it will display
the final geocache coordinates and an optional hint.

In January 2024 the previous platform was shut down. It worked on the
[https://www.geotrailsw.com/mob/index.php](https://www.geotrailsw.com/mob/index.php)
address. This is a replacement service.

## Links

* Deployed app: [geocaching.fracz.com/mob](https://geocaching.fracz.com/mob/)
* Geocaching Forum
  topic: [https://forums.geocaching.com/GC/index.php?/topic/396356-mob-caches-a-new-tool/](https://forums.geocaching.com/GC/index.php?/topic/396356-mob-caches-a-new-tool/)
* Issues: [https://github.com/fracz/geocache-mob/issues](https://github.com/fracz/geocache-mob/issues)

## Deployment

The app is expected to work under `/mob/` subdirectory. 
It also requires a database access configuration
to be stored inside a `../../config/db.php` file (outside the public html
directory) and a Google Recaptcha secret stored in the 
`../../config/recaptcha_secret`. It required at least PHP 7.4.
Example database config:

```php
<?php
return [
    'DB_HOST' => 'localhost',
    'DB_PORT' => 3306,
    'DB_NAME' => 'geocaching',
    'DB_USER' => 'app',
    'DB_PASSWORD' => 'XXX',
];
```

## SQL Schema

```sql
CREATE TABLE `mob_cache`
(
    `code`          varchar(10) NOT NULL,
    `coords`        varchar(50) NOT NULL,
    `radius`        int(11) NOT NULL DEFAULT 50,
    `min_attendees` int(11) NOT NULL DEFAULT 5,
    `final_coords`  varchar(50) DEFAULT NULL,
    `final_hint`    varchar(255)         DEFAULT NULL,
    `authcode`      char(40)    NOT NULL,
    `created_at`    datetime    NOT NULL DEFAULT current_timestamp(),
    `delay_s`       int(11) NOT NULL DEFAULT 60,
    PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `mob_attendee`
(
    `code`        varchar(10) NOT NULL,
    `ip`          varchar(40) NOT NULL,
    `last_online` datetime    NOT NULL DEFAULT current_timestamp(),
    `last_coords` varchar(50) NOT NULL,
    PRIMARY KEY (`code`, `ip`),
    CONSTRAINT `mob_attendees_FK` FOREIGN KEY (`code`) REFERENCES `mob_cache` (`code`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```
