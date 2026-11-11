-- Lets a report have up to 2 photos. Run once on a database that was imported before this change.
-- phpMyAdmin: select your database, open the SQL tab, paste this file, click Go.
-- Safe to run twice: it never creates the table or copies a photo a second time.
CREATE TABLE IF NOT EXISTS report_photos (
    photo_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id  INT UNSIGNED NOT NULL,
    file_name  VARCHAR(255) NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (photo_id),
    KEY idx_photos_report (report_id),
    CONSTRAINT fk_photos_report FOREIGN KEY (report_id)
        REFERENCES waste_reports (report_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Copy the single photo of each older report into the new table.
INSERT INTO report_photos (report_id, file_name, sort_order)
SELECT r.report_id, r.photo, 1
FROM waste_reports r
WHERE r.photo IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM report_photos p WHERE p.report_id = r.report_id);
