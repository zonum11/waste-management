-- Starter data. Run after schema.sql: mysql -u root -p < database/seed.sql

USE waste_management;

-- Zones: each zone is one address option on the Register page ("Barangay Pogi Zone 1, Sitio Merete")
INSERT INTO zones (zone_name, barangay_name, description) VALUES
    ('Zone 1', 'Barangay Pogi', 'Sitio Merete'),
    ('Zone 2', 'Barangay Pogi', 'Sitio Munoz'),
    ('Zone 3', 'Barangay Pogi', 'Sitio Arzadon'),
    ('Zone 4', 'Barangay Pogi', 'Sitio Daguison'),
    ('Zone 5', 'Barangay Pogi', 'Sitio Serafica'),
    ('Zone 6', 'Barangay Pogi', 'Sitio Aranda');

-- Waste types (common Philippine categories)
INSERT INTO waste_types (type_name, description) VALUES
    ('Biodegradable', 'Food scraps, leaves, and other organic waste'),
    ('Recyclable',    'Paper, plastic bottles, glass, metal cans'),
    ('Residual',      'Waste that cannot be recycled or composted'),
    ('Special',       'Hazardous waste such as batteries, bulbs, and chemicals');

-- Sample schedules and the zones they cover
INSERT INTO collection_schedule (collect_day, collect_time, status) VALUES
    ('Monday',   '07:00:00', 'active'),
    ('Wednesday','07:00:00', 'active'),
    ('Friday',   '07:00:00', 'active');

INSERT INTO schedule_zones (sched_id, zone_id) VALUES
    (1, 1), (1, 2),
    (2, 3),
    (3, 1), (3, 2), (3, 3);

-- Dev admin (admin@barangay.local / Admin@12345): change before going online
INSERT INTO users (fname, lname, email, phone_number, address, role, password_hash) VALUES
    ('System', 'Admin', 'admin@barangay.local', NULL, 'Barangay Hall', 'admin',
     '$2y$10$CBmmrJW6evsGtqfpvro4hecaVaFuYOJGZefRUbNUzcIi3dIGtC2eu');
