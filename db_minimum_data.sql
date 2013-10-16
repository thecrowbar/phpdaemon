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

/*Data for the table `merchant_info` */

INSERT  INTO `merchant_info`(`id`,`mid`,`tid`,`host_capture`,`zip_code`,`merch_cat_code`,`network_international_id`,`north_merchant_num`,`omaha_merchant_num`) VALUES (1,'123456789012','1234567',1,'123450000','5968','001','','');

/*Data for the table `terminal_type_list` */

INSERT INTO `terminal_type_list` (`id`, `type_name`) VALUES(1, 'TERMINAL_TYPE_RETAIL');
INSERT INTO `terminal_type_list` (`id`, `type_name`) VALUES(2, 'TERMINAL_TYPE_DIRECT_MARKETING');

/*Data for the table `terminal_info` */

INSERT  INTO `terminal_info`(`id`,`merchant_id`,`terminal_id`,`L5300_SerialNumber`, `terminal_type_id`) VALUES (1,1,'1234567','',1);



/*Data for the table `trans_type_list` */

INSERT  INTO `trans_type_list`(`id`,`type_name`,`type_id`) VALUES (1,'RECURRING_BILLING',1),(2,'REALTIME',2),(3,'REFUND',3),(4,'DIRECT_MARKETING',4),(5,'REVERSAL',5),(6,'TIMEOUT_REVERSAL',6),(7,'ZERO_DOLLAR_AVS_CHECK',7),(8,'ZERO_DOLLAR_CVC_CHECK',8),(9,'ZERO_DOLLAR_AVS_N_CVC',9),(10,'SUBSEQUENT_RECURRING',10),(11,'INITIAL_RECURRING',11);

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
