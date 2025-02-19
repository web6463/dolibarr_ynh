-- Insert new configuration values from user input
INSERT INTO `llx_const` (`name`, `value`, `type`) VALUES
('YUNOHOST_BASE_DOMAIN', '{{syncyunohost_base_domain}}', 'chaine'),
('YUNOHOST_MAIN_GROUP', '{{syncyunohost_main_group}}', 'chaine'),
('YUNOHOST_OLD_MEMBERS', '{{syncyunohost_old_members}}', 'chaine')
ON DUPLICATE KEY UPDATE value = VALUES(value);
