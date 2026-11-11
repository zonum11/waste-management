-- Sets the zones to the six real ones of Barangay Pogi (these are the address choices on the Register page).
-- Run it in phpMyAdmin: select your database, open the SQL tab, paste this whole file, click Go.
-- Safe to run again, and whatever zones you have now: an existing "Zone 1".."Zone 6" is renamed,
-- a missing one is added. Residents and reports keep working; only the zone names change.

UPDATE zones SET barangay_name = 'Barangay Pogi', description = 'Sitio Merete' WHERE zone_name = 'Zone 1';
UPDATE zones SET barangay_name = 'Barangay Pogi', description = 'Sitio Munoz' WHERE zone_name = 'Zone 2';
UPDATE zones SET barangay_name = 'Barangay Pogi', description = 'Sitio Arzadon' WHERE zone_name = 'Zone 3';
UPDATE zones SET barangay_name = 'Barangay Pogi', description = 'Sitio Daguison' WHERE zone_name = 'Zone 4';
UPDATE zones SET barangay_name = 'Barangay Pogi', description = 'Sitio Serafica' WHERE zone_name = 'Zone 5';
UPDATE zones SET barangay_name = 'Barangay Pogi', description = 'Sitio Aranda' WHERE zone_name = 'Zone 6';

INSERT INTO zones (zone_name, barangay_name, description)
SELECT 'Zone 1', 'Barangay Pogi', 'Sitio Merete' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE zone_name = 'Zone 1');

INSERT INTO zones (zone_name, barangay_name, description)
SELECT 'Zone 2', 'Barangay Pogi', 'Sitio Munoz' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE zone_name = 'Zone 2');

INSERT INTO zones (zone_name, barangay_name, description)
SELECT 'Zone 3', 'Barangay Pogi', 'Sitio Arzadon' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE zone_name = 'Zone 3');

INSERT INTO zones (zone_name, barangay_name, description)
SELECT 'Zone 4', 'Barangay Pogi', 'Sitio Daguison' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE zone_name = 'Zone 4');

INSERT INTO zones (zone_name, barangay_name, description)
SELECT 'Zone 5', 'Barangay Pogi', 'Sitio Serafica' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE zone_name = 'Zone 5');

INSERT INTO zones (zone_name, barangay_name, description)
SELECT 'Zone 6', 'Barangay Pogi', 'Sitio Aranda' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE zone_name = 'Zone 6');

-- Refresh the saved address text of residents who already have a zone (so their header and
-- schedule banner show e.g. "Zone 3 - Sitio Arzadon" instead of an old value).
UPDATE users
SET address = (SELECT CONCAT(z.barangay_name, ' ', z.zone_name, ', ', z.description) FROM zones z WHERE z.zone_id = users.zone_id)
WHERE zone_id IS NOT NULL;
