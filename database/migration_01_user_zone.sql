-- Adds the zone each resident belongs to (so residents only see their own zone's schedule).
-- Run ONCE on a database that already has the tables: select the database in phpMyAdmin,
-- open the SQL tab, paste this and click Go. New installs do not need it (schema.sql has the column).
-- Existing residents keep working: they are asked to choose a zone the next time they log in.

ALTER TABLE users
    ADD COLUMN zone_id INT UNSIGNED NULL AFTER address,
    ADD CONSTRAINT fk_users_zone FOREIGN KEY (zone_id) REFERENCES zones (zone_id) ON DELETE SET NULL;
