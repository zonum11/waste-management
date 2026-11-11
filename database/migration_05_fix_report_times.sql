-- OPTIONAL, run only ONCE, and only on an online database that saved times in the hosting server's
-- time zone before the time fix. It moves the old dates to Philippine time (UTC+8).
-- Running it twice would shift the old rows twice, so run it once and do not repeat it.
-- If your old rows were already correct (for example a local XAMPP database), do NOT run it.
-- phpMyAdmin: select your database, open the SQL tab, paste this file, click Go.

-- Hours the server clock is away from Philippine time, worked out from the server itself.
SET @shift_minutes = 8 * 60 - TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW());

UPDATE waste_reports  SET date_reported = DATE_ADD(date_reported, INTERVAL @shift_minutes MINUTE);
UPDATE report_history SET updated_at    = DATE_ADD(updated_at,    INTERVAL @shift_minutes MINUTE);
UPDATE users          SET date_created  = DATE_ADD(date_created,  INTERVAL @shift_minutes MINUTE);
