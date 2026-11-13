CREATE TABLE IF NOT EXISTS `about` (
    `about_id` INT(11) NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `content` LONGTEXT NOT NULL,
    `image` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`about_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `about` (`title`, `content`)
SELECT
    'About EstateScope',
    '<p>EstateScope helps people discover homes and make informed real estate decisions. Browse properties, compare your options, and connect with the people who can help you find the right place.</p>'
WHERE NOT EXISTS (SELECT 1 FROM `about`);
