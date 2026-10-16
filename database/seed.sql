
-- Starter data for the Waste Management System
-- Database: wastlpsb_waste-management
-- Select this database in phpMyAdmin before importing.
-- Run schema.sql before running this file.

-- Zones
INSERT INTO zones (zone_name, barangay_name, description)
SELECT 'Zone 1', 'Barangay Sample', 'Sample zone - replace with real data'
WHERE NOT EXISTS (
    SELECT 1 FROM zones
    WHERE zone_name = 'Zone 1'
      AND barangay_name = 'Barangay Sample'
);

INSERT INTO zones (zone_name, barangay_name, description)
SELECT 'Zone 2', 'Barangay Sample', 'Sample zone - replace with real data'
WHERE NOT EXISTS (
    SELECT 1 FROM zones
    WHERE zone_name = 'Zone 2'
      AND barangay_name = 'Barangay Sample'
);

INSERT INTO zones (zone_name, barangay_name, description)
SELECT 'Zone 3', 'Barangay Sample', 'Sample zone - replace with real data'
WHERE NOT EXISTS (
    SELECT 1 FROM zones
    WHERE zone_name = 'Zone 3'
      AND barangay_name = 'Barangay Sample'
);

-- Waste types
INSERT INTO waste_types (type_name, description)
SELECT 'Biodegradable', 'Food scraps, leaves, and other organic waste'
WHERE NOT EXISTS (
    SELECT 1 FROM waste_types WHERE type_name = 'Biodegradable'
);

INSERT INTO waste_types (type_name, description)
SELECT 'Recyclable', 'Paper, plastic bottles, glass, metal cans'
WHERE NOT EXISTS (
    SELECT 1 FROM waste_types WHERE type_name = 'Recyclable'
);

INSERT INTO waste_types (type_name, description)
SELECT 'Residual', 'Waste that cannot be recycled or composted'
WHERE NOT EXISTS (
    SELECT 1 FROM waste_types WHERE type_name = 'Residual'
);

INSERT INTO waste_types (type_name, description)
SELECT 'Special', 'Hazardous waste such as batteries, bulbs, and chemicals'
WHERE NOT EXISTS (
    SELECT 1 FROM waste_types WHERE type_name = 'Special'
);

-- Collection schedules
-- Avoid adding duplicate schedules for the same day and time.
INSERT INTO collection_schedule (collect_day, collect_time, status)
SELECT 'Monday', '07:00:00', 'active'
WHERE NOT EXISTS (
    SELECT 1 FROM collection_schedule
    WHERE collect_day = 'Monday'
      AND collect_time = '07:00:00'
);

INSERT INTO collection_schedule (collect_day, collect_time, status)
SELECT 'Wednesday', '07:00:00', 'active'
WHERE NOT EXISTS (
    SELECT 1 FROM collection_schedule
    WHERE collect_day = 'Wednesday'
      AND collect_time = '07:00:00'
);

INSERT INTO collection_schedule (collect_day, collect_time, status)
SELECT 'Friday', '07:00:00', 'active'
WHERE NOT EXISTS (
    SELECT 1 FROM collection_schedule
    WHERE collect_day = 'Friday'
      AND collect_time = '07:00:00'
);

-- Link schedules to zones using their actual IDs.
INSERT INTO schedule_zones (sched_id, zone_id)
SELECT cs.sched_id, z.zone_id
FROM collection_schedule cs
JOIN zones z ON z.barangay_name = 'Barangay Sample'
WHERE
    (cs.collect_day = 'Monday'
     AND z.zone_name IN ('Zone 1', 'Zone 2')
     AND cs.collect_time = '07:00:00')
    OR
    (cs.collect_day = 'Wednesday'
     AND z.zone_name = 'Zone 3'
     AND cs.collect_time = '07:00:00')
    OR
    (cs.collect_day = 'Friday'
     AND z.zone_name IN ('Zone 1', 'Zone 2', 'Zone 3')
     AND cs.collect_time = '07:00:00')
ON DUPLICATE KEY UPDATE sched_id = VALUES(sched_id);

-- Development administrator account
-- Email: admin@barangay.local
-- Intended password: Admin@12345
-- Change this account and password before public deployment.
-- Insert only if the email does not already exist.
INSERT INTO users (
    fname,
    lname,
    email,
    phone_number,
    address,
    role,
    password_hash
)
SELECT
    'System',
    'Admin',
    'admin@barangay.local',
    NULL,
    'Barangay Hall',
    'admin',
    '$2y$10$CBmmrJW6evsGtqfpvro4hecaVaFuYOJGZefRUbNUzcIi3dIGtC2eu'
WHERE NOT EXISTS (
    SELECT 1 FROM users
    WHERE email = 'admin@barangay.local'
);