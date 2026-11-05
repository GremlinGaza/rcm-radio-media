CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value TEXT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS programs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  description TEXT,
  category VARCHAR(100) DEFAULT 'RCM ORIGINAL',
  schedule VARCHAR(160) DEFAULT '',
  image_url VARCHAR(1000) DEFAULT '',
  media_url VARCHAR(1000) DEFAULT '',
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  publish_at DATETIME DEFAULT NULL,
  unpublish_at DATETIME DEFAULT NULL,
  public_since DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_programs_published (is_published, is_featured, sort_order)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS news (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(250) NOT NULL,
  summary TEXT NOT NULL,
  category VARCHAR(100) DEFAULT 'Kenya Update',
  source_name VARCHAR(200) DEFAULT '',
  source_url VARCHAR(1500) DEFAULT '',
  published_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  public_since DATETIME DEFAULT NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_news_published (is_published, published_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS news_feed_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_key VARCHAR(40) NOT NULL,
  external_id VARCHAR(191) NOT NULL,
  news_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_news_feed_item (source_key, external_id),
  INDEX idx_news_feed_news (news_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS news_feed_status (
  source_key VARCHAR(40) PRIMARY KEY,
  source_name VARCHAR(100) NOT NULL,
  region VARCHAR(40) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'not_synced',
  last_synced_at DATETIME DEFAULT NULL,
  stories_synced INT UNSIGNED NOT NULL DEFAULT 0,
  message VARCHAR(255) NOT NULL DEFAULT '',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS partners (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  short_name VARCHAR(20) NOT NULL,
  description VARCHAR(400) NOT NULL DEFAULT '',
  website_url VARCHAR(1000) DEFAULT '',
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS socials (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  platform VARCHAR(100) NOT NULL,
  url VARCHAR(1000) NOT NULL DEFAULT '',
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS social_updates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  summary TEXT NOT NULL,
  platform VARCHAR(30) NOT NULL,
  content_type VARCHAR(40) NOT NULL DEFAULT 'Recorded show',
  content_url VARCHAR(1500) NOT NULL,
  published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  is_published TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_social_updates_published (is_published, published_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS social_sync_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  platform VARCHAR(30) NOT NULL,
  external_id VARCHAR(191) NOT NULL,
  social_update_id INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_social_sync_item (platform, external_id),
  INDEX idx_social_sync_update (social_update_id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS social_sync_status (
  platform VARCHAR(30) PRIMARY KEY,
  status VARCHAR(20) NOT NULL DEFAULT 'not_configured',
  last_synced_at DATETIME DEFAULT NULL,
  items_synced INT UNSIGNED NOT NULL DEFAULT 0,
  message VARCHAR(255) NOT NULL DEFAULT '',
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS social_sync_tokens (
  platform VARCHAR(30) PRIMARY KEY,
  encrypted_payload MEDIUMBLOB NOT NULL,
  nonce VARBINARY(12) NOT NULL,
  auth_tag VARBINARY(16) NOT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS contact_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  subject VARCHAR(180) DEFAULT '',
  message TEXT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_messages_created (created_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS email_subscribers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(254) NOT NULL UNIQUE,
  wants_programs TINYINT(1) NOT NULL DEFAULT 1,
  wants_news TINYINT(1) NOT NULL DEFAULT 1,
  pending_wants_programs TINYINT(1) DEFAULT NULL,
  pending_wants_news TINYINT(1) DEFAULT NULL,
  confirmation_token_hash CHAR(64) DEFAULT NULL,
  confirmed_at DATETIME DEFAULT NULL,
  unsubscribed_at DATETIME DEFAULT NULL,
  last_digest_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_email_subscribers_delivery (confirmed_at,unsubscribed_at,last_digest_at)
) ENGINE=InnoDB;

INSERT INTO settings (setting_key,setting_value) VALUES
('stream_url',''),('contact_email','hello@rcmradiomedia.co.ke')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

INSERT INTO partners (name,short_name,description,website_url,sort_order) VALUES
('Aura & Co Ltd','A&CO','A partner in creativity, ideas and shared growth.','',1),
('RCM Online College','RCM OC','Learning opportunities and skills for a connected future.','',2),
('Tric Gaming Hub','TRIC','Gaming culture, community and next-level play.','',3),
('RCM Welfare Association','RCM WA','Community support and welfare initiatives.','',4);

INSERT INTO socials (platform,url,sort_order) VALUES
('TikTok','https://www.tiktok.com/@rcm.radio',1),
('Facebook','https://web.facebook.com/p/RCM-RADIO-61585037325434/',2),
('Instagram','https://www.instagram.com/popular/rcm-radio/',3),
('YouTube','https://www.youtube.com/channel/UCWZD5jrTFf8GoIigABi8RkA',4);

INSERT INTO programs (title,description,category,schedule,image_url,is_featured,is_published,sort_order)
SELECT 'RCM Breakfast','Hosted by Noah Esokon. Start the weekday with RCM Breakfast.','Weekday Shows','Weekdays - 6:00 AM - 10:00 AM','assets/images/programs/rcm-breakfast.jpg',1,1,1
WHERE NOT EXISTS (SELECT 1 FROM programs WHERE title='RCM Breakfast');
INSERT INTO programs (title,description,category,schedule,image_url,is_featured,is_published,sort_order)
SELECT 'Gumzo 254','Hosted by Gloria Bridget and Rael Jepngetich.','Weekday Shows','Weekdays - 10:00 AM - 1:00 PM','',1,1,2
WHERE NOT EXISTS (SELECT 1 FROM programs WHERE title='Gumzo 254');
INSERT INTO programs (title,description,category,schedule,image_url,is_featured,is_published,sort_order)
SELECT 'Vibes Mtaani','Hosted by Jared Kwe and Craft Yun.','Weekday Shows','Weekdays - 1:00 PM - 4:00 PM','assets/images/programs/vibes-mtaani.jpg',1,1,3
WHERE NOT EXISTS (SELECT 1 FROM programs WHERE title='Vibes Mtaani');
INSERT INTO programs (title,description,category,schedule,image_url,is_featured,is_published,sort_order)
SELECT 'RCM Drive','Hosted by Festus Andayi.','Weekday Shows','Weekdays - 4:00 PM - 6:00 PM','',0,1,4
WHERE NOT EXISTS (SELECT 1 FROM programs WHERE title='RCM Drive');
INSERT INTO programs (title,description,category,schedule,image_url,is_featured,is_published,sort_order)
SELECT 'Around the World','Hosted by Olilo and Papsi.','Weekday Shows','Weekdays - 6:00 PM - 9:00 PM','',0,1,5
WHERE NOT EXISTS (SELECT 1 FROM programs WHERE title='Around the World');
INSERT INTO programs (title,description,category,schedule,image_url,is_featured,is_published,sort_order)
SELECT 'Random Sato','Saturday show hosted by Noah Esokon.','Weekend Shows','Every Saturday - 8:00 AM - 10:00 AM','',0,1,6
WHERE NOT EXISTS (SELECT 1 FROM programs WHERE title='Random Sato');
INSERT INTO programs (title,description,category,schedule,image_url,is_featured,is_published,sort_order)
SELECT 'Weekend Wrap Up','Hosted by Gloria Bridget and Rael Jepngetich.','Weekend Shows','Every Saturday - 10:00 AM - 1:00 PM','',0,1,7
WHERE NOT EXISTS (SELECT 1 FROM programs WHERE title='Weekend Wrap Up');
INSERT INTO programs (title,description,category,schedule,image_url,is_featured,is_published,sort_order)
SELECT 'Taarab Taratibu','Sunday show hosted by Festus Andayi.','Weekend Shows','Every Sunday - 2:00 PM - 4:00 PM','assets/images/programs/taarab-taratibu.jpg',0,1,8
WHERE NOT EXISTS (SELECT 1 FROM programs WHERE title='Taarab Taratibu');

INSERT INTO news (title,summary,category,source_name,source_url,published_at,is_published) VALUES
('Welcome to the RCM newsroom','This is sample content. Replace it with a verified, current Kenya story and a direct link to its original publisher before launch.','EDITORIAL NOTE','RCM Radio Media','https://www.kenyanews.go.ke/',CURRENT_TIMESTAMP,0);
