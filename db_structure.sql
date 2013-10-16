/*
SQLyog Community v10.3 
MySQL - 5.5.29-0ubuntu0.12.10.1 : Database - fd_transactions
*********************************************************************
*/


/*!40101 SET NAMES utf8 */;

/*!40101 SET SQL_MODE=''*/;

/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
CREATE DATABASE /*!32312 IF NOT EXISTS*/`fd_transactions` /*!40100 DEFAULT CHARACTER SET latin1 */;

USE `fd_transactions`;

/*Table structure for table `avs_response_log` */

DROP TABLE IF EXISTS `avs_response_log`;

CREATE TABLE `avs_response_log` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `acct_number_hash` VARCHAR(32) COLLATE latin1_general_ci NOT NULL DEFAULT '',
  `avs_date` DATE NOT NULL DEFAULT '0000-00-00',
  `avs_response` VARCHAR(2) COLLATE latin1_general_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=INNODB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;

/*Table structure for table `ds_trans` */

DROP TABLE IF EXISTS `ds_trans`;

CREATE TABLE `ds_trans` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_name` VARCHAR(50) DEFAULT NULL,
  `ds_num` VARCHAR(25) DEFAULT NULL,
  `receipt_number` INT(12) DEFAULT NULL,
  `response_code` VARCHAR(4) DEFAULT NULL,
  `auth_iden_response` VARCHAR(10) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=INNODB DEFAULT CHARSET=latin1;

/*Table structure for table `fd_discover_compliance` */

DROP TABLE IF EXISTS `fd_discover_compliance`;

CREATE TABLE `fd_discover_compliance` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `trans_id` INT(11) NOT NULL,
  `processing_code` VARCHAR(6) NOT NULL DEFAULT '',
  `sys_trace_audit_num` VARCHAR(6) NOT NULL DEFAULT '',
  `pos_entry_mode` VARCHAR(4) NOT NULL DEFAULT '',
  `local_tran_time` VARCHAR(6) NOT NULL DEFAULT '',
  `local_tran_date` VARCHAR(6) NOT NULL DEFAULT '',
  `response_code` VARCHAR(2) NOT NULL DEFAULT '',
  `pos_data` VARCHAR(13) NOT NULL DEFAULT '',
  `track_data_condition_code` VARCHAR(2) NOT NULL DEFAULT '',
  `avs_result` VARCHAR(1) NOT NULL DEFAULT '',
  `nrid` VARCHAR(15) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `trans_id` (`trans_id`)
) ENGINE=MYISAM AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;

/*Table structure for table `fd_mastercard_qualification` */

DROP TABLE IF EXISTS `fd_mastercard_qualification`;

CREATE TABLE `fd_mastercard_qualification` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `trans_id` INT(11) NOT NULL,
  `TD_card_data_input_cap` CHAR(1) NOT NULL DEFAULT '',
  `TD_cardholder_auth_cap` CHAR(1) NOT NULL DEFAULT '',
  `TD_card_capture_cap` CHAR(1) NOT NULL DEFAULT '',
  `term_oper_environ` CHAR(1) NOT NULL DEFAULT '',
  `cardholder_present_data` CHAR(1) NOT NULL DEFAULT '',
  `card_present_data` CHAR(1) NOT NULL DEFAULT '',
  `CD_input_mode` CHAR(1) NOT NULL DEFAULT '',
  `cardholder_auth_method` CHAR(1) NOT NULL DEFAULT '',
  `cardholder_auth_entity` CHAR(1) NOT NULL DEFAULT '',
  `card_data_output_cap` CHAR(1) NOT NULL DEFAULT '',
  `term_data_output_cap` CHAR(1) NOT NULL DEFAULT '',
  `pin_capture_cap` CHAR(1) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `trans_id` (`trans_id`)
) ENGINE=MYISAM AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;

/*Table structure for table `fd_trans` */

DROP TABLE IF EXISTS `fd_trans`;

CREATE TABLE `fd_trans` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `master_trans_id` INT(11) NOT NULL DEFAULT '-1' ,
  `trans_type` INT(11) NOT NULL DEFAULT '-1',
  `terminal_id` INT(11) DEFAULT NULL,
  `create_dt` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `auth_submit_dt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  `capture_submit_dt` DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  `customer_site_id` VARCHAR(4) NOT NULL DEFAULT '',
  `user_name` VARCHAR(30) NOT NULL,
  `sale_site_id` VARCHAR(4) NOT NULL,
  `sx_order_number` INT(11) NOT NULL,
  `msg_type` VARCHAR(4) NOT NULL DEFAULT '',
  `TK2` VARCHAR(256) NOT NULL DEFAULT '',
  `pri_acct_no` varchar(1024) NOT NULL,
  `cc_last_four` varchar(4) NOT NULL DEFAULT '',
  `cc_type` varchar(2) NOT NULL DEFAULT '',
  `processing_code` varchar(6) NOT NULL DEFAULT '000000',
  `trans_amount` decimal(7,2) NOT NULL DEFAULT '0.00',
  `amount_resp` decimal(7,2) NOT NULL DEFAULT '0.00',
  `receipt_number` int(11) NOT NULL DEFAULT '0',
  `trans_dt` datetime NOT NULL,
  `cc_exp` varchar(4) NOT NULL DEFAULT '',
  `pos_entry_pin` varchar(3) NOT NULL DEFAULT '',
  `pos_condition_code` varchar(2) NOT NULL DEFAULT '00',
  `retrieval_reference_num` varchar(12) NOT NULL DEFAULT '',
  `auth_iden_response` varchar(6) NOT NULL DEFAULT '',
  `response_code` varchar(2) NOT NULL DEFAULT '',
  `avs_response` varchar(1) DEFAULT NULL,
  `avs_data` varchar(31) NOT NULL DEFAULT '',
  `response_text` varchar(255) NOT NULL DEFAULT '',
  `table49_response` varchar(10) NOT NULL DEFAULT '',
  `acquirer_reference_data` tinyint(1) NOT NULL DEFAULT 1,
  `refunded` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `terminal_id` (`terminal_id`),
  KEY `trans_type_idx` (`trans_type`),
  CONSTRAINT `fd_trans_ibfk_1` FOREIGN KEY (`terminal_id`) REFERENCES `terminal_info` (`id`),
  CONSTRAINT `trans_type_fk` FOREIGN KEY (`trans_type`) REFERENCES `trans_type_list` (`type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;

/*Table structure for table `fd_visa_compliance` */

DROP TABLE IF EXISTS `fd_visa_compliance`;

CREATE TABLE `fd_visa_compliance` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trans_id` int(11) NOT NULL,
  `card_level_response_code` varchar(2) NOT NULL DEFAULT '',
  `source_reason_code` varchar(1) NOT NULL DEFAULT '',
  `unknown` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `trans_id` (`trans_id`)
) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;

/*Table structure for table `full_auth_reversal` */

DROP TABLE IF EXISTS `full_auth_reversal`;

CREATE TABLE `full_auth_reversal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `original_id` int(11) NOT NULL DEFAULT '-1',
  `reversal_id` int(11) NOT NULL DEFAULT '-1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;

/*Table structure for table `merchant_info` */

DROP TABLE IF EXISTS `merchant_info`;

CREATE TABLE `merchant_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mid` varchar(20) NOT NULL,
  `tid` varchar(10) NOT NULL,
  `host_capture` int(1) NOT NULL DEFAULT '0',
  `zip_code` varchar(9) NOT NULL,
  `merch_cat_code` varchar(4) NOT NULL,
  `network_international_id` varchar(3) NOT NULL DEFAULT '001',
  `north_merchant_num` varchar(20) NOT NULL DEFAULT '',
  `omaha_merchant_num` varchar(20) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;

/*Table structure for table `table14_amex` */

DROP TABLE IF EXISTS `table14_amex`;

CREATE TABLE `table14_amex` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trans_id` int(11) NOT NULL,
  `aei` varchar(1) CHARACTER SET latin1 NOT NULL DEFAULT 'X',
  `issuer_trans_id` varchar(15) CHARACTER SET latin1 NOT NULL DEFAULT '',
  `filler` varchar(6) CHARACTER SET latin1 NOT NULL DEFAULT '      ',
  `pos_data` varchar(12) CHARACTER SET latin1 NOT NULL DEFAULT '',
  `filler2` varchar(12) CHARACTER SET latin1 NOT NULL DEFAULT '            ',
  `seller_id` varchar(20) CHARACTER SET latin1 NOT NULL DEFAULT '                    ',
  PRIMARY KEY (`id`),
  KEY `trans_id` (`trans_id`),
  CONSTRAINT `table14_amex_ibfk_1` FOREIGN KEY (`trans_id`) REFERENCES `fd_trans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;

/*Table structure for table `table14_ds` */

DROP TABLE IF EXISTS `table14_ds`;

CREATE TABLE `table14_ds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trans_id` int(11) NOT NULL,
  `di` varchar(1) CHARACTER SET latin1 NOT NULL DEFAULT 'X',
  `issuer_trans_id` varchar(15) CHARACTER SET latin1 NOT NULL DEFAULT '',
  `filler` varchar(6) CHARACTER SET latin1 NOT NULL DEFAULT '      ',
  `filler2` varchar(12) CHARACTER SET latin1 NOT NULL DEFAULT '            ',
  `total_auth_amount` varchar(12) CHARACTER SET latin1 NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `trans_id` (`trans_id`),
  CONSTRAINT `table14_ds_ibfk_1` FOREIGN KEY (`trans_id`) REFERENCES `fd_trans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;

/*Table structure for table `table14_mc` */

DROP TABLE IF EXISTS `table14_mc`;

CREATE TABLE `table14_mc` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trans_id` int(11) NOT NULL,
  `aci` varchar(1) CHARACTER SET latin1 NOT NULL DEFAULT 'Y',
  `banknet_date` varchar(4) CHARACTER SET latin1 NOT NULL DEFAULT '',
  `banknet_reference` varchar(9) CHARACTER SET latin1 NOT NULL DEFAULT '',
  `filler` varchar(2) CHARACTER SET latin1 NOT NULL DEFAULT '  ',
  `cvc_error_code` varchar(1) CHARACTER SET latin1 NOT NULL DEFAULT '',
  `pos_entry_mode_change` varchar(1) CHARACTER SET latin1 NOT NULL DEFAULT '',
  `trans_edit_code_error` varchar(1) CHARACTER SET latin1 NOT NULL DEFAULT '',
  `filler2` varchar(1) CHARACTER SET latin1 NOT NULL DEFAULT ' ',
  `mkt_specific_data_ind` varchar(1) CHARACTER SET latin1 NOT NULL DEFAULT ' ',
  `filler3` varchar(13) CHARACTER SET latin1 NOT NULL DEFAULT '             ',
  `total_auth_amount` decimal(12,0) NOT NULL DEFAULT '0',
  `addtl_mc_settle_date` varchar(4) CHARACTER SET latin1 NOT NULL DEFAULT '',
  `addtl_banknet_mc_ref` varchar(9) CHARACTER SET latin1 NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `trans_id` (`trans_id`),
  CONSTRAINT `table14_mc_ibfk_1` FOREIGN KEY (`trans_id`) REFERENCES `fd_trans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;

/*Table structure for table `table14_visa` */

DROP TABLE IF EXISTS `table14_visa`;

CREATE TABLE `table14_visa` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `trans_id` int(11) NOT NULL,
  `aci` varchar(1) CHARACTER SET latin1 NOT NULL DEFAULT 'Y',
  `issuer_trans_id` varchar(15) CHARACTER SET latin1 NOT NULL DEFAULT '',
  `validation_code` varchar(4) CHARACTER SET latin1 NOT NULL DEFAULT '',
  `mkt_specific_data_ind` varchar(1) CHARACTER SET latin1 NOT NULL DEFAULT ' ',
  `rps` varchar(1) CHARACTER SET latin1 NOT NULL DEFAULT ' ',
  `first_auth_amount` decimal(12,0) NOT NULL DEFAULT '0',
  `total_auth_amount` decimal(12,0) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `trans_id` (`trans_id`),
  CONSTRAINT `table14_visa_ibfk_1` FOREIGN KEY (`trans_id`) REFERENCES `fd_trans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;

/*Table structure for table `td_ds_trans` */

DROP TABLE IF EXISTS `td_ds_trans`;

CREATE TABLE `td_ds_trans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ds_num` varchar(25) DEFAULT NULL,
  `auth_iden_response` varbinary(10) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

/*Table structure for table `terminal_info` */

DROP TABLE IF EXISTS `terminal_info`;

CREATE TABLE `terminal_info` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `merchant_id` int(11) NOT NULL,
  `terminal_id` varchar(12) NOT NULL DEFAULT '',
  `L5300_SerialNumber` varchar(25) NOT NULL DEFAULT '',
  `terminal_type_id` INT(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `merchant_id` (`merchant_id`),
  CONSTRAINT `terminal_info_ibfk_1` FOREIGN KEY (`merchant_id`) REFERENCES `merchant_info` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;

/*Table structure for table `terminal_type_list` */

DROP TABLE IF EXISTS `terminal_type_list`;

CREATE TABLE `terminal_type_list` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;

/*Table structure for table `trans_type_list` */

DROP TABLE IF EXISTS `trans_type_list`;

CREATE TABLE `trans_type_list` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(50) COLLATE latin1_general_ci NOT NULL DEFAULT '',
  `type_id` int(11) NOT NULL DEFAULT '-1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_id` (`type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;

/* Trigger structure for table `fd_trans` */

DELIMITER $$

/*!50003 DROP TRIGGER*//*!50032 IF EXISTS */ /*!50003 `set_trace_number` */$$

/*!50003 CREATE */  /*!50003 TRIGGER `set_trace_number` BEFORE INSERT ON `fd_trans` FOR EACH ROW BEGIN
	-- get the current trace number for this merchant_id, terminal_id combo
	DECLARE _trace_num INTEGER;
	-- msg types 0100 gets a new trace number, all others copy the original 0100 trace number
	IF NEW.msg_type = '0100'  or NEW.msg_type = '0400' THEN
		SELECT IFNULL(MAX(`receipt_number`),0) INTO _trace_num 
			FROM fd_trans; 
		-- 2013-04-17 receipt_number (bit 11) can not be the same for a different terminal ID on the same day
		-- I use a company unique counter for the receipt_number
			-- WHERE terminal_id = NEW.terminal_id ;
		-- increment our trace_number
		SET _trace_num = _trace_num + 1;
		-- wrap our _trace_num at 999999
		-- WHILE _trace_num > 999999 DO
		-- 	SET _trace_num = _trace_num - 999999;
		-- END WHILE;
		IF _trace_num > 999999 THEN SET _trace_num = 1;
		ELSEIF _trace_num < 1 THEN SET _trace_num = 1;
		END IF;
	ELSE
		-- get the original trace number
		-- TODO
		SET _trace_num = 0;
	END IF;
	SET NEW.receipt_number = _trace_num;
END */$$


DELIMITER ;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
