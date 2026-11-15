-- MySQL dump 10.13  Distrib 8.4.7, for Win64 (x86_64)
--
-- Host: 127.0.0.1    Database: smart blood bank
-- ------------------------------------------------------
-- Server version	8.4.7

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` int DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `details` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`log_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
INSERT INTO `audit_logs` VALUES (1,2,'RECORD_DONATION',1,'2026-11-14 18:02:29','Logged O- unit from Hassan Raza'),(2,3,'MARK_CLEARED',1,'2026-11-14 18:02:29','Lab cleared unit 1 to AVAILABLE'),(3,2,'CONFIRM_ALLOCATION',3,'2026-11-14 18:02:29','Issued unit 3 against request 3'),(4,3,'USER_LOGIN',3,'2026-11-14 18:53:30','User signed in'),(5,6,'USER_REGISTERED',6,'2026-11-14 18:54:04','User registered with role ADMIN'),(6,6,'DONOR_CREATED',6,'2026-11-14 19:07:14','New donor registered: muneeb (O_POSITIVE)'),(7,6,'DONOR_UPDATED',6,'2026-11-14 19:07:33','Donor #6 updated (muneeb, O_POSITIVE)'),(8,6,'DONATION_LOGGED',7,'2026-11-14 19:07:45','Unit #7 from Donor muneeb (O_POSITIVE). Status: QUARANTINED'),(9,1,'DEMO_DATA_SEEDED',NULL,'2026-11-14 21:10:42','Repeatable demo import: synthetic donors, inventory units, and hospital requests.'),(10,1,'DEMO_DATA_SEEDED',NULL,'2026-11-14 21:16:32','Repeatable demo import: synthetic donors, inventory units, and hospital requests.'),(11,6,'LAB_UNIT_CLEARED',7,'2026-11-14 21:17:17','Unit #7 (O+) passed lab clearance and is now AVAILABLE. Notes: All 5 TTI screens Non-Reactive. ABO/Rh (O+) verified.'),(12,8,'USER_LOGIN',8,'2026-11-14 21:25:06','User signed in'),(13,8,'HOSPITAL_ORDER_PLACED',34,'2026-11-14 21:27:33','Hospital (HOSP-DEMO) placed order #34 for 1 unit(s) of O- (Urgency: ROUTINE, Ref: Ward 4B, Room 12, Patient 3, Asadullah (Acute Hemorrhagic Shock))'),(14,7,'USER_LOGIN',7,'2026-11-14 21:28:25','User signed in'),(15,7,'USER_LOGIN',7,'2026-11-14 21:58:28','User signed in'),(16,7,'USER_LOGIN',7,'2026-11-14 21:59:06','User signed in'),(17,7,'USER_LOGIN',7,'2026-11-15 01:57:18','User signed in'),(18,6,'USER_LOGIN',6,'2026-11-15 22:41:34','User signed in'),(19,7,'USER_LOGIN',7,'2026-11-15 22:50:58','User signed in'),(20,7,'USER_LOGIN',7,'2026-11-15 23:27:44','User signed in'),(21,9,'DEMO_ACCOUNT_RESET',NULL,'2026-11-16 00:20:24','Local demo credentials reset; previous user logins revoked and historical rows retained.'),(22,14,'DONOR_PROFILE_SUBMITTED',14,'2026-11-16 00:29:04','Donor profile submitted for facility 1; staff verification pending.'),(23,14,'USER_REGISTERED',14,'2026-11-16 00:29:04','User account created with role USER and status APPROVED through public registration.'),(24,14,'DONOR_PROFILE_UPDATED',14,'2026-11-16 00:57:14','Donor updated their own profile; staff verification required.'),(25,14,'DONOR_PROFILE_UPDATED',14,'2026-11-16 00:57:17','Donor updated their own profile; staff verification required.'),(26,14,'DONOR_PROFILE_UPDATED',14,'2026-11-16 00:57:18','Donor updated their own profile; staff verification required.'),(27,14,'DONOR_PROFILE_UPDATED',14,'2026-11-16 00:57:28','Donor updated their own profile; staff verification required.'),(28,14,'DONOR_PROFILE_UPDATED',14,'2026-11-16 00:57:29','Donor updated their own profile; staff verification required.'),(29,14,'DONOR_PROFILE_UPDATED',14,'2026-11-16 00:57:29','Donor updated their own profile; staff verification required.'),(30,14,'DONOR_PROFILE_UPDATED',14,'2026-11-16 00:57:29','Donor updated their own profile; staff verification required.'),(31,14,'DONOR_PROFILE_UPDATED',14,'2026-11-16 00:57:40','Donor updated their own profile; staff verification required.'),(32,10,'USER_LOGIN',10,'2026-11-16 00:58:30','User signed in'),(33,10,'DONOR_PROFILE_VERIFIED',14,'2026-11-16 00:58:56','Staff verified donor profile and linked donor #67 at facility 1.'),(34,11,'USER_LOGIN',11,'2026-11-16 00:59:29','User signed in');
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `audit_logs_reject_update` BEFORE UPDATE ON `audit_logs` FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log entries are append-only';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `audit_logs_reject_delete` BEFORE DELETE ON `audit_logs` FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit log entries are append-only';
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `blood_requests`
--

DROP TABLE IF EXISTS `blood_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `blood_requests` (
  `request_id` int NOT NULL AUTO_INCREMENT,
  `submitted_by` int NOT NULL,
  `serving_institution_id` int NOT NULL DEFAULT '1',
  `requested_blood_group` enum('O_NEGATIVE','O_POSITIVE','A_NEGATIVE','A_POSITIVE','B_NEGATIVE','B_POSITIVE','AB_NEGATIVE','AB_POSITIVE') COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` int NOT NULL,
  `urgency` enum('ROUTINE','URGENT','CRITICAL') COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('PENDING','FULFILLED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `request_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `patient_ref` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  KEY `submitted_by` (`submitted_by`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `blood_requests`
--

LOCK TABLES `blood_requests` WRITE;
/*!40000 ALTER TABLE `blood_requests` DISABLE KEYS */;
INSERT INTO `blood_requests` VALUES (1,4,1,'A_POSITIVE',2,'CRITICAL','PENDING','2026-10-12 09:15:00',NULL),(2,4,1,'O_NEGATIVE',1,'URGENT','PENDING','2026-10-12 11:40:00',NULL),(3,4,1,'B_POSITIVE',1,'ROUTINE','FULFILLED','2026-10-10 16:00:00',NULL),(4,8,1,'O_NEGATIVE',2,'ROUTINE','PENDING','2026-11-13 12:00:00','DEMO-REQUEST-001'),(5,8,1,'O_POSITIVE',3,'ROUTINE','PENDING','2026-11-12 12:00:00','DEMO-REQUEST-002'),(6,8,1,'A_NEGATIVE',1,'ROUTINE','PENDING','2026-11-11 12:00:00','DEMO-REQUEST-003'),(7,8,1,'A_POSITIVE',2,'URGENT','PENDING','2026-11-10 12:00:00','DEMO-REQUEST-004'),(8,8,1,'B_NEGATIVE',3,'ROUTINE','PENDING','2026-11-09 12:00:00','DEMO-REQUEST-005'),(9,8,1,'B_POSITIVE',1,'ROUTINE','PENDING','2026-11-08 12:00:00','DEMO-REQUEST-006'),(10,8,1,'AB_NEGATIVE',2,'ROUTINE','PENDING','2026-11-07 12:00:00','DEMO-REQUEST-007'),(11,8,1,'AB_POSITIVE',3,'URGENT','PENDING','2026-11-06 12:00:00','DEMO-REQUEST-008'),(12,8,1,'O_NEGATIVE',1,'ROUTINE','PENDING','2026-11-05 12:00:00','DEMO-REQUEST-009'),(13,8,1,'O_POSITIVE',2,'CRITICAL','PENDING','2026-11-04 12:00:00','DEMO-REQUEST-010'),(14,8,1,'A_NEGATIVE',3,'ROUTINE','PENDING','2026-11-03 12:00:00','DEMO-REQUEST-011'),(15,8,1,'A_POSITIVE',1,'URGENT','PENDING','2026-11-02 12:00:00','DEMO-REQUEST-012'),(16,8,1,'B_NEGATIVE',2,'ROUTINE','PENDING','2026-11-01 12:00:00','DEMO-REQUEST-013'),(17,8,1,'B_POSITIVE',3,'ROUTINE','PENDING','2026-10-31 12:00:00','DEMO-REQUEST-014'),(18,8,1,'AB_NEGATIVE',1,'ROUTINE','PENDING','2026-10-30 12:00:00','DEMO-REQUEST-015'),(19,8,1,'AB_POSITIVE',2,'URGENT','PENDING','2026-10-29 12:00:00','DEMO-REQUEST-016'),(20,8,1,'O_NEGATIVE',3,'ROUTINE','PENDING','2026-10-28 12:00:00','DEMO-REQUEST-017'),(21,8,1,'O_POSITIVE',1,'ROUTINE','PENDING','2026-10-27 12:00:00','DEMO-REQUEST-018'),(22,8,1,'A_NEGATIVE',2,'ROUTINE','PENDING','2026-10-26 12:00:00','DEMO-REQUEST-019'),(23,8,1,'A_POSITIVE',3,'CRITICAL','PENDING','2026-11-14 12:00:00','DEMO-REQUEST-020'),(24,8,1,'B_NEGATIVE',1,'ROUTINE','PENDING','2026-11-13 12:00:00','DEMO-REQUEST-021'),(25,8,1,'B_POSITIVE',2,'ROUTINE','PENDING','2026-11-12 12:00:00','DEMO-REQUEST-022'),(26,8,1,'AB_NEGATIVE',3,'ROUTINE','PENDING','2026-11-11 12:00:00','DEMO-REQUEST-023'),(27,8,1,'AB_POSITIVE',1,'URGENT','PENDING','2026-11-10 12:00:00','DEMO-REQUEST-024'),(28,8,1,'O_NEGATIVE',2,'ROUTINE','PENDING','2026-11-09 12:00:00','DEMO-REQUEST-025'),(29,8,1,'O_POSITIVE',3,'ROUTINE','PENDING','2026-11-08 12:00:00','DEMO-REQUEST-026'),(30,8,1,'A_NEGATIVE',1,'ROUTINE','PENDING','2026-11-07 12:00:00','DEMO-REQUEST-027'),(31,8,1,'A_POSITIVE',2,'URGENT','PENDING','2026-11-06 12:00:00','DEMO-REQUEST-028'),(32,8,1,'B_NEGATIVE',3,'ROUTINE','PENDING','2026-11-05 12:00:00','DEMO-REQUEST-029'),(33,8,1,'B_POSITIVE',1,'CRITICAL','PENDING','2026-11-04 12:00:00','DEMO-REQUEST-030'),(34,8,1,'O_NEGATIVE',1,'ROUTINE','PENDING','2026-11-14 21:27:33','Ward 4B, Room 12, Patient 3, Asadullah (Acute Hemorrhagic Shock)');
/*!40000 ALTER TABLE `blood_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `blood_units`
--

DROP TABLE IF EXISTS `blood_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `blood_units` (
  `unit_id` int NOT NULL AUTO_INCREMENT,
  `donor_id` int DEFAULT NULL,
  `blood_group` enum('O_NEGATIVE','O_POSITIVE','A_NEGATIVE','A_POSITIVE','B_NEGATIVE','B_POSITIVE','AB_NEGATIVE','AB_POSITIVE') COLLATE utf8mb4_unicode_ci NOT NULL,
  `collection_date` date NOT NULL,
  `expiry_date` date NOT NULL,
  `status` enum('QUARANTINED','AVAILABLE','RESERVED','ISSUED','EXPIRED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'QUARANTINED',
  `institution_id` int NOT NULL,
  PRIMARY KEY (`unit_id`),
  KEY `donor_id` (`donor_id`)
) ENGINE=InnoDB AUTO_INCREMENT=128 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `blood_units`
--

LOCK TABLES `blood_units` WRITE;
/*!40000 ALTER TABLE `blood_units` DISABLE KEYS */;
INSERT INTO `blood_units` VALUES (1,1,'O_NEGATIVE','2026-10-01','2026-11-12','EXPIRED',1),(2,1,'O_NEGATIVE','2026-10-20','2026-12-01','QUARANTINED',1),(3,2,'O_POSITIVE','2026-09-25','2026-11-06','EXPIRED',1),(4,3,'A_POSITIVE','2026-10-05','2026-11-16','AVAILABLE',1),(5,4,'B_NEGATIVE','2026-08-20','2026-10-01','EXPIRED',1),(6,5,'AB_POSITIVE','2026-10-12','2026-11-23','RESERVED',1),(7,6,'O_POSITIVE','2026-11-14','2026-12-19','AVAILABLE',137),(8,7,'O_POSITIVE','2026-10-14','2026-11-18','AVAILABLE',137),(9,8,'O_POSITIVE','2026-10-29','2026-12-03','AVAILABLE',137),(10,9,'A_POSITIVE','2026-10-16','2026-11-20','AVAILABLE',137),(11,3,'A_POSITIVE','2026-11-03','2026-12-08','AVAILABLE',137),(12,4,'B_POSITIVE','2026-10-18','2026-11-22','AVAILABLE',137),(13,10,'O_NEGATIVE','2026-10-15','2026-11-19','AVAILABLE',137),(14,11,'O_NEGATIVE','2026-11-07','2026-12-12','AVAILABLE',137),(15,12,'AB_POSITIVE','2026-10-24','2026-11-28','AVAILABLE',137),(16,7,'O_POSITIVE','2026-10-14','2026-11-18','AVAILABLE',137),(17,8,'O_POSITIVE','2026-10-29','2026-12-03','AVAILABLE',137),(18,9,'A_POSITIVE','2026-10-16','2026-11-20','AVAILABLE',137),(19,3,'A_POSITIVE','2026-11-03','2026-12-08','AVAILABLE',137),(20,4,'B_POSITIVE','2026-10-18','2026-11-22','AVAILABLE',137),(21,10,'O_NEGATIVE','2026-10-15','2026-11-19','AVAILABLE',137),(22,11,'O_NEGATIVE','2026-11-07','2026-12-12','AVAILABLE',137),(23,12,'AB_POSITIVE','2026-10-24','2026-11-28','AVAILABLE',137),(24,13,'O_NEGATIVE','2026-10-09','2026-11-13','EXPIRED',1),(25,13,'O_NEGATIVE','2026-10-02','2026-11-06','EXPIRED',1),(26,14,'O_POSITIVE','2026-10-23','2026-11-27','AVAILABLE',1),(27,14,'O_POSITIVE','2026-10-16','2026-11-20','AVAILABLE',1),(28,15,'A_NEGATIVE','2026-11-06','2026-12-11','AVAILABLE',1),(29,15,'A_NEGATIVE','2026-10-30','2026-12-04','AVAILABLE',1),(30,16,'A_POSITIVE','2026-09-30','2026-11-04','EXPIRED',1),(31,16,'A_POSITIVE','2026-11-13','2026-12-18','AVAILABLE',1),(32,17,'B_NEGATIVE','2026-10-14','2026-11-18','AVAILABLE',1),(33,17,'B_NEGATIVE','2026-10-07','2026-11-11','EXPIRED',1),(34,18,'B_POSITIVE','2026-10-28','2026-12-02','AVAILABLE',1),(35,18,'B_POSITIVE','2026-10-21','2026-11-25','QUARANTINED',1),(36,19,'AB_NEGATIVE','2026-11-11','2026-12-16','AVAILABLE',1),(37,19,'AB_NEGATIVE','2026-11-04','2026-12-09','RESERVED',1),(38,20,'AB_POSITIVE','2026-10-05','2026-11-09','EXPIRED',1),(39,20,'AB_POSITIVE','2026-09-28','2026-11-02','EXPIRED',1),(40,21,'O_NEGATIVE','2026-10-19','2026-11-23','AVAILABLE',1),(41,21,'O_NEGATIVE','2026-10-12','2026-11-16','AVAILABLE',1),(42,22,'O_POSITIVE','2026-11-02','2026-12-07','AVAILABLE',1),(43,22,'O_POSITIVE','2026-10-26','2026-11-30','AVAILABLE',1),(44,23,'A_NEGATIVE','2026-09-26','2026-10-31','EXPIRED',1),(45,23,'A_NEGATIVE','2026-11-09','2026-12-14','AVAILABLE',1),(46,24,'A_POSITIVE','2026-10-10','2026-11-14','AVAILABLE',1),(47,24,'A_POSITIVE','2026-10-03','2026-11-07','EXPIRED',1),(48,25,'B_NEGATIVE','2026-10-24','2026-11-28','RESERVED',1),(49,25,'B_NEGATIVE','2026-10-17','2026-11-21','AVAILABLE',1),(50,26,'B_POSITIVE','2026-11-07','2026-12-12','AVAILABLE',1),(51,26,'B_POSITIVE','2026-10-31','2026-12-05','AVAILABLE',1),(52,27,'AB_NEGATIVE','2026-10-01','2026-11-05','EXPIRED',1),(53,27,'AB_NEGATIVE','2026-11-14','2026-12-19','AVAILABLE',1),(54,28,'AB_POSITIVE','2026-10-15','2026-11-19','AVAILABLE',1),(55,28,'AB_POSITIVE','2026-10-08','2026-11-12','EXPIRED',1),(56,29,'O_NEGATIVE','2026-10-29','2026-12-03','AVAILABLE',1),(57,29,'O_NEGATIVE','2026-10-22','2026-11-26','QUARANTINED',1),(58,30,'O_POSITIVE','2026-11-12','2026-12-17','AVAILABLE',1),(59,30,'O_POSITIVE','2026-11-05','2026-12-10','AVAILABLE',1),(60,31,'A_NEGATIVE','2026-10-06','2026-11-10','EXPIRED',1),(61,31,'A_NEGATIVE','2026-09-29','2026-11-03','EXPIRED',1),(62,32,'A_POSITIVE','2026-10-20','2026-11-24','AVAILABLE',1),(63,32,'A_POSITIVE','2026-10-13','2026-11-17','RESERVED',1),(64,33,'B_NEGATIVE','2026-11-03','2026-12-08','AVAILABLE',1),(65,33,'B_NEGATIVE','2026-10-27','2026-12-01','AVAILABLE',1),(66,34,'B_POSITIVE','2026-09-27','2026-11-01','EXPIRED',1),(67,34,'B_POSITIVE','2026-11-10','2026-12-15','AVAILABLE',1),(68,35,'AB_NEGATIVE','2026-10-11','2026-11-15','AVAILABLE',1),(69,35,'AB_NEGATIVE','2026-10-04','2026-11-08','EXPIRED',1),(70,36,'AB_POSITIVE','2026-10-25','2026-11-29','AVAILABLE',1),(71,36,'AB_POSITIVE','2026-10-18','2026-11-22','AVAILABLE',1),(72,37,'O_NEGATIVE','2026-11-08','2026-12-13','AVAILABLE',1),(73,37,'O_NEGATIVE','2026-11-01','2026-12-06','AVAILABLE',1),(74,38,'O_POSITIVE','2026-10-02','2026-11-06','EXPIRED',1),(75,38,'O_POSITIVE','2026-09-25','2026-10-30','EXPIRED',1),(76,39,'A_NEGATIVE','2026-10-16','2026-11-20','AVAILABLE',1),(77,39,'A_NEGATIVE','2026-10-09','2026-11-13','EXPIRED',1),(78,40,'A_POSITIVE','2026-10-30','2026-12-04','AVAILABLE',1),(79,40,'A_POSITIVE','2026-10-23','2026-11-27','QUARANTINED',1),(80,41,'B_NEGATIVE','2026-11-13','2026-12-18','AVAILABLE',1),(81,41,'B_NEGATIVE','2026-11-06','2026-12-11','AVAILABLE',1),(82,42,'B_POSITIVE','2026-10-07','2026-11-11','EXPIRED',1),(83,42,'B_POSITIVE','2026-09-30','2026-11-04','EXPIRED',1),(84,43,'AB_NEGATIVE','2026-10-21','2026-11-25','AVAILABLE',1),(85,43,'AB_NEGATIVE','2026-10-14','2026-11-18','AVAILABLE',1),(86,44,'AB_POSITIVE','2026-11-04','2026-12-09','AVAILABLE',1),(87,44,'AB_POSITIVE','2026-10-28','2026-12-02','AVAILABLE',1),(88,45,'O_NEGATIVE','2026-09-28','2026-11-02','EXPIRED',1),(89,45,'O_NEGATIVE','2026-11-11','2026-12-16','RESERVED',1),(90,46,'O_POSITIVE','2026-10-12','2026-11-16','AVAILABLE',1),(91,46,'O_POSITIVE','2026-10-05','2026-11-09','EXPIRED',1),(92,47,'A_NEGATIVE','2026-10-26','2026-11-30','AVAILABLE',1),(93,47,'A_NEGATIVE','2026-10-19','2026-11-23','AVAILABLE',1),(94,48,'A_POSITIVE','2026-11-09','2026-12-14','AVAILABLE',1),(95,48,'A_POSITIVE','2026-11-02','2026-12-07','AVAILABLE',1),(96,49,'B_NEGATIVE','2026-10-03','2026-11-07','EXPIRED',1),(97,49,'B_NEGATIVE','2026-09-26','2026-10-31','EXPIRED',1),(98,50,'B_POSITIVE','2026-10-17','2026-11-21','AVAILABLE',1),(99,50,'B_POSITIVE','2026-10-10','2026-11-14','AVAILABLE',1),(100,51,'AB_NEGATIVE','2026-10-31','2026-12-05','RESERVED',1),(101,51,'AB_NEGATIVE','2026-10-24','2026-11-28','QUARANTINED',1),(102,52,'AB_POSITIVE','2026-11-14','2026-12-19','AVAILABLE',1),(103,52,'AB_POSITIVE','2026-11-07','2026-12-12','AVAILABLE',1),(104,53,'O_NEGATIVE','2026-10-08','2026-11-12','EXPIRED',1),(105,53,'O_NEGATIVE','2026-10-01','2026-11-05','EXPIRED',1),(106,54,'O_POSITIVE','2026-10-22','2026-11-26','AVAILABLE',1),(107,54,'O_POSITIVE','2026-10-15','2026-11-19','AVAILABLE',1),(108,55,'A_NEGATIVE','2026-11-05','2026-12-10','AVAILABLE',1),(109,55,'A_NEGATIVE','2026-10-29','2026-12-03','AVAILABLE',1),(110,56,'A_POSITIVE','2026-09-29','2026-11-03','EXPIRED',1),(111,56,'A_POSITIVE','2026-11-12','2026-12-17','AVAILABLE',1),(112,57,'B_NEGATIVE','2026-10-13','2026-11-17','AVAILABLE',1),(113,57,'B_NEGATIVE','2026-10-06','2026-11-10','EXPIRED',1),(114,58,'B_POSITIVE','2026-10-27','2026-12-01','AVAILABLE',1),(115,58,'B_POSITIVE','2026-10-20','2026-11-24','RESERVED',1),(116,59,'AB_NEGATIVE','2026-11-10','2026-12-15','AVAILABLE',1),(117,59,'AB_NEGATIVE','2026-11-03','2026-12-08','AVAILABLE',1),(118,60,'AB_POSITIVE','2026-10-04','2026-11-08','EXPIRED',1),(119,60,'AB_POSITIVE','2026-09-27','2026-11-01','EXPIRED',1),(120,61,'O_POSITIVE','2026-10-14','2026-11-18','AVAILABLE',1),(121,62,'O_POSITIVE','2026-10-29','2026-12-03','AVAILABLE',1),(122,63,'A_POSITIVE','2026-10-16','2026-11-20','AVAILABLE',1),(123,3,'A_POSITIVE','2026-11-03','2026-12-08','AVAILABLE',1),(124,4,'B_POSITIVE','2026-10-18','2026-11-22','AVAILABLE',1),(125,64,'O_NEGATIVE','2026-10-15','2026-11-19','AVAILABLE',1),(126,65,'O_NEGATIVE','2026-11-07','2026-12-12','AVAILABLE',1),(127,66,'AB_POSITIVE','2026-10-24','2026-11-28','AVAILABLE',1);
/*!40000 ALTER TABLE `blood_units` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `contact_messages`
--

DROP TABLE IF EXISTS `contact_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_messages` (
  `message_id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` enum('ADMIN','STAFF','LAB_TECHNICIAN','HOSPITAL_REQUESTER','USER') COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sent_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `contact_messages`
--

LOCK TABLES `contact_messages` WRITE;
/*!40000 ALTER TABLE `contact_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `contact_messages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `demo_seed_metadata`
--

DROP TABLE IF EXISTS `demo_seed_metadata`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demo_seed_metadata` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(50) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `demo_seed_metadata`
--

LOCK TABLES `demo_seed_metadata` WRITE;
/*!40000 ALTER TABLE `demo_seed_metadata` DISABLE KEYS */;
INSERT INTO `demo_seed_metadata` VALUES ('anchor_date','2026-11-14');
/*!40000 ALTER TABLE `demo_seed_metadata` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `donation_appointments`
--

DROP TABLE IF EXISTS `donation_appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `donation_appointments` (
  `appointment_id` int NOT NULL AUTO_INCREMENT,
  `donor_id` int NOT NULL,
  `facility_id` int NOT NULL,
  `requested_date` date NOT NULL,
  `requested_time` time NOT NULL,
  `scheduled_date` date DEFAULT NULL,
  `scheduled_time` time DEFAULT NULL,
  `status` enum('PENDING','CONFIRMED','DECLINED','CANCELLED','COMPLETED','NO_SHOW') NOT NULL DEFAULT 'PENDING',
  `staff_note` varchar(300) DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`appointment_id`),
  KEY `idx_donation_appointments_facility_status` (`facility_id`,`status`,`requested_date`),
  KEY `idx_donation_appointments_donor` (`donor_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `donation_appointments`
--

LOCK TABLES `donation_appointments` WRITE;
/*!40000 ALTER TABLE `donation_appointments` DISABLE KEYS */;
/*!40000 ALTER TABLE `donation_appointments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `donor_profiles`
--

DROP TABLE IF EXISTS `donor_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `donor_profiles` (
  `user_id` int NOT NULL,
  `facility_id` int NOT NULL,
  `blood_group` varchar(16) NOT NULL,
  `profile_status` enum('PENDING','VERIFIED','REJECTED') NOT NULL DEFAULT 'PENDING',
  `consent_at` datetime NOT NULL,
  `donor_id` int DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_donor_profiles_donor_id` (`donor_id`),
  KEY `idx_donor_profiles_facility_status` (`facility_id`,`profile_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `donor_profiles`
--

LOCK TABLES `donor_profiles` WRITE;
/*!40000 ALTER TABLE `donor_profiles` DISABLE KEYS */;
INSERT INTO `donor_profiles` VALUES (13,1,'O+','PENDING','2026-11-16 00:20:24',NULL,NULL,NULL,'2026-11-16 00:20:24','2026-11-16 00:20:24'),(14,1,'B_POSITIVE','VERIFIED','2026-11-16 00:29:04',67,10,'2026-11-16 00:58:56','2026-11-16 00:29:04','2026-11-16 00:58:56');
/*!40000 ALTER TABLE `donor_profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `donors`
--

DROP TABLE IF EXISTS `donors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `donors` (
  `donor_id` int NOT NULL AUTO_INCREMENT,
  `managed_by` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_details` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `blood_group` enum('O_NEGATIVE','O_POSITIVE','A_NEGATIVE','A_POSITIVE','B_NEGATIVE','B_POSITIVE','AB_NEGATIVE','AB_POSITIVE') COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_donation_date` date DEFAULT NULL,
  PRIMARY KEY (`donor_id`),
  UNIQUE KEY `uq_donors_user_id` (`user_id`),
  KEY `managed_by` (`managed_by`)
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `donors`
--

LOCK TABLES `donors` WRITE;
/*!40000 ALTER TABLE `donors` DISABLE KEYS */;
INSERT INTO `donors` VALUES (1,2,NULL,'Hassan Raza','03001234567','O_NEGATIVE','2026-09-01'),(2,2,NULL,'Ayesha Khan','03019876543','O_POSITIVE','2026-09-20'),(3,2,NULL,'Bilal Ahmed','03215551234','A_POSITIVE','2026-10-05'),(4,2,NULL,'Fatima Noor','03331237890','B_NEGATIVE','2026-08-15'),(5,2,NULL,'Usman Tariq','03451112233','AB_POSITIVE','2026-10-12'),(6,6,NULL,'muneeb','03165195031','O_POSITIVE','2026-11-14'),(7,6,NULL,'Ali Raza','03001112233','O_POSITIVE','2026-11-14'),(8,6,NULL,'Zainab Bibi','03012223344','O_POSITIVE','2026-11-14'),(9,6,NULL,'Hamza Tariq','03023334455','A_POSITIVE','2026-11-14'),(10,6,NULL,'Usman Khalid','03056667788','O_NEGATIVE','2026-11-14'),(11,6,NULL,'Ayesha Siddiqua','03067778899','O_NEGATIVE','2026-11-14'),(12,6,NULL,'Danish Ali','03078889900','AB_POSITIVE','2026-11-14'),(13,1,NULL,'DEMO Donor 001','DEMO ONLY - no real contact details','O_NEGATIVE','2026-09-04'),(14,1,NULL,'DEMO Donor 002','DEMO ONLY - no real contact details','O_POSITIVE','2026-09-03'),(15,1,NULL,'DEMO Donor 003','DEMO ONLY - no real contact details','A_NEGATIVE','2026-09-02'),(16,1,NULL,'DEMO Donor 004','DEMO ONLY - no real contact details','A_POSITIVE','2026-09-01'),(17,1,NULL,'DEMO Donor 005','DEMO ONLY - no real contact details','B_NEGATIVE','2026-08-31'),(18,1,NULL,'DEMO Donor 006','DEMO ONLY - no real contact details','B_POSITIVE','2026-08-30'),(19,1,NULL,'DEMO Donor 007','DEMO ONLY - no real contact details','AB_NEGATIVE','2026-08-29'),(20,1,NULL,'DEMO Donor 008','DEMO ONLY - no real contact details','AB_POSITIVE','2026-08-28'),(21,1,NULL,'DEMO Donor 009','DEMO ONLY - no real contact details','O_NEGATIVE','2026-08-27'),(22,1,NULL,'DEMO Donor 010','DEMO ONLY - no real contact details','O_POSITIVE','2026-08-26'),(23,1,NULL,'DEMO Donor 011','DEMO ONLY - no real contact details','A_NEGATIVE','2026-08-25'),(24,1,NULL,'DEMO Donor 012','DEMO ONLY - no real contact details','A_POSITIVE','2026-08-24'),(25,1,NULL,'DEMO Donor 013','DEMO ONLY - no real contact details','B_NEGATIVE','2026-08-23'),(26,1,NULL,'DEMO Donor 014','DEMO ONLY - no real contact details','B_POSITIVE','2026-08-22'),(27,1,NULL,'DEMO Donor 015','DEMO ONLY - no real contact details','AB_NEGATIVE','2026-08-21'),(28,1,NULL,'DEMO Donor 016','DEMO ONLY - no real contact details','AB_POSITIVE','2026-08-20'),(29,1,NULL,'DEMO Donor 017','DEMO ONLY - no real contact details','O_NEGATIVE','2026-08-19'),(30,1,NULL,'DEMO Donor 018','DEMO ONLY - no real contact details','O_POSITIVE','2026-08-18'),(31,1,NULL,'DEMO Donor 019','DEMO ONLY - no real contact details','A_NEGATIVE','2026-08-17'),(32,1,NULL,'DEMO Donor 020','DEMO ONLY - no real contact details','A_POSITIVE','2026-08-16'),(33,1,NULL,'DEMO Donor 021','DEMO ONLY - no real contact details','B_NEGATIVE','2026-08-15'),(34,1,NULL,'DEMO Donor 022','DEMO ONLY - no real contact details','B_POSITIVE','2026-08-14'),(35,1,NULL,'DEMO Donor 023','DEMO ONLY - no real contact details','AB_NEGATIVE','2026-08-13'),(36,1,NULL,'DEMO Donor 024','DEMO ONLY - no real contact details','AB_POSITIVE','2026-08-12'),(37,1,NULL,'DEMO Donor 025','DEMO ONLY - no real contact details','O_NEGATIVE','2026-08-11'),(38,1,NULL,'DEMO Donor 026','DEMO ONLY - no real contact details','O_POSITIVE','2026-08-10'),(39,1,NULL,'DEMO Donor 027','DEMO ONLY - no real contact details','A_NEGATIVE','2026-08-09'),(40,1,NULL,'DEMO Donor 028','DEMO ONLY - no real contact details','A_POSITIVE','2026-08-08'),(41,1,NULL,'DEMO Donor 029','DEMO ONLY - no real contact details','B_NEGATIVE','2026-08-07'),(42,1,NULL,'DEMO Donor 030','DEMO ONLY - no real contact details','B_POSITIVE','2026-08-06'),(43,1,NULL,'DEMO Donor 031','DEMO ONLY - no real contact details','AB_NEGATIVE','2026-08-05'),(44,1,NULL,'DEMO Donor 032','DEMO ONLY - no real contact details','AB_POSITIVE','2026-08-04'),(45,1,NULL,'DEMO Donor 033','DEMO ONLY - no real contact details','O_NEGATIVE','2026-08-03'),(46,1,NULL,'DEMO Donor 034','DEMO ONLY - no real contact details','O_POSITIVE','2026-08-02'),(47,1,NULL,'DEMO Donor 035','DEMO ONLY - no real contact details','A_NEGATIVE','2026-08-01'),(48,1,NULL,'DEMO Donor 036','DEMO ONLY - no real contact details','A_POSITIVE','2026-07-31'),(49,1,NULL,'DEMO Donor 037','DEMO ONLY - no real contact details','B_NEGATIVE','2026-07-30'),(50,1,NULL,'DEMO Donor 038','DEMO ONLY - no real contact details','B_POSITIVE','2026-07-29'),(51,1,NULL,'DEMO Donor 039','DEMO ONLY - no real contact details','AB_NEGATIVE','2026-07-28'),(52,1,NULL,'DEMO Donor 040','DEMO ONLY - no real contact details','AB_POSITIVE','2026-07-27'),(53,1,NULL,'DEMO Donor 041','DEMO ONLY - no real contact details','O_NEGATIVE','2026-07-26'),(54,1,NULL,'DEMO Donor 042','DEMO ONLY - no real contact details','O_POSITIVE','2026-07-25'),(55,1,NULL,'DEMO Donor 043','DEMO ONLY - no real contact details','A_NEGATIVE','2026-07-24'),(56,1,NULL,'DEMO Donor 044','DEMO ONLY - no real contact details','A_POSITIVE','2026-07-23'),(57,1,NULL,'DEMO Donor 045','DEMO ONLY - no real contact details','B_NEGATIVE','2026-07-22'),(58,1,NULL,'DEMO Donor 046','DEMO ONLY - no real contact details','B_POSITIVE','2026-07-21'),(59,1,NULL,'DEMO Donor 047','DEMO ONLY - no real contact details','AB_NEGATIVE','2026-07-20'),(60,1,NULL,'DEMO Donor 048','DEMO ONLY - no real contact details','AB_POSITIVE','2026-07-19'),(61,7,NULL,'Ali Raza','03001112233','O_POSITIVE','2026-11-14'),(62,7,NULL,'Zainab Bibi','03012223344','O_POSITIVE','2026-11-14'),(63,7,NULL,'Hamza Tariq','03023334455','A_POSITIVE','2026-11-14'),(64,7,NULL,'Usman Khalid','03056667788','O_NEGATIVE','2026-11-14'),(65,7,NULL,'Ayesha Siddiqua','03067778899','O_NEGATIVE','2026-11-14'),(66,7,NULL,'Danish Ali','03078889900','AB_POSITIVE','2026-11-14'),(67,10,14,'mahir','03331232323','B_POSITIVE',NULL);
/*!40000 ALTER TABLE `donors` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `updated_by` int DEFAULT NULL,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `transaction_id` int NOT NULL AUTO_INCREMENT,
  `unit_id` int NOT NULL,
  `request_id` int NOT NULL,
  `allocation_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `quantity` int NOT NULL,
  `institution_id` int NOT NULL,
  PRIMARY KEY (`transaction_id`),
  KEY `unit_id` (`unit_id`),
  KEY `request_id` (`request_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
INSERT INTO `transactions` VALUES (1,3,3,'2026-10-10 16:20:00',1,1);
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `user_id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `email` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `role` enum('ADMIN','STAFF','LAB_TECHNICIAN','HOSPITAL_REQUESTER','USER') COLLATE utf8mb4_unicode_ci NOT NULL,
  `blood_group` varchar(8) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `institution_id` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `institution_verified` tinyint(1) NOT NULL DEFAULT '0',
  `approval_status` enum('PENDING','APPROVED','REJECTED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'APPROVED',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin.kiran','Kiran','disabled+1@invalid.local',NULL,'$2y$12$RvI/.tRFE5xReXMhgJj.jerplckxcVtQJwecmnsBx0ySiwYHp2hUG',1,'USER',NULL,'1',0,'REJECTED','2026-11-14 13:02:29'),(2,'staff.ali','Ali','disabled+2@invalid.local',NULL,'$2y$12$RvI/.tRFE5xReXMhgJj.jerplckxcVtQJwecmnsBx0ySiwYHp2hUG',1,'USER',NULL,'1',0,'REJECTED','2026-11-14 13:02:29'),(3,'lab.sara','Sara','disabled+3@invalid.local',NULL,'$2y$12$RvI/.tRFE5xReXMhgJj.jerplckxcVtQJwecmnsBx0ySiwYHp2hUG',1,'USER',NULL,'1',0,'REJECTED','2026-11-14 13:02:29'),(4,'hosp.ahmed','Ahmed','disabled+4@invalid.local',NULL,'$2y$12$RvI/.tRFE5xReXMhgJj.jerplckxcVtQJwecmnsBx0ySiwYHp2hUG',1,'USER',NULL,'2',0,'REJECTED','2026-11-14 13:02:29'),(6,'abbas','abbas','disabled+6@invalid.local',NULL,'$2y$12$RvI/.tRFE5xReXMhgJj.jerplckxcVtQJwecmnsBx0ySiwYHp2hUG',1,'USER','O+','137',0,'REJECTED','2026-11-14 13:54:04'),(7,'staffdemo','Demo Staff','disabled+7@invalid.local',NULL,'$2y$12$RvI/.tRFE5xReXMhgJj.jerplckxcVtQJwecmnsBx0ySiwYHp2hUG',1,'USER','O+','FUSST-BB',0,'REJECTED','2026-11-14 15:03:18'),(8,'hospitaldemo','DEMO Hospital Requester','disabled+8@invalid.local',NULL,'$2y$12$RvI/.tRFE5xReXMhgJj.jerplckxcVtQJwecmnsBx0ySiwYHp2hUG',1,'USER','O+','HOSP-DEMO',0,'REJECTED','2026-11-14 16:10:41'),(9,'admin_demo','Demo Administrator','admin.demo@example.test','03000000001','$2y$12$qimWnEQxFVcuEb5LrM5gAO36DibfwmG33ZSIPf8ygUWU6zNxjyJ1e',0,'ADMIN','O+','FUSST-BB',1,'APPROVED','2026-11-15 19:20:24'),(10,'staff_demo','Demo Blood Bank Staff','staff.demo@example.test','03000000002','$2y$10$ghurnrrLdFIu/VesYso3S.Pp1wXQIh4XGdTvTixk3SwzRArJxOTNO',0,'STAFF','A+','FUSST-BB',1,'APPROVED','2026-11-15 19:20:24'),(11,'hospital_demo','Demo Hospital Requester','hospital.demo@example.test','03000000003','$2y$10$nDd4PrISi73txw4dA4HfFOQE.SIubfZ8xwI.5yVJZpYD/nm0xgvqa',0,'HOSPITAL_REQUESTER','O+','HOSP-DEMO',1,'APPROVED','2026-11-15 19:20:24'),(12,'lab_demo','Demo Lab Technician','lab.demo@example.test','03000000004','$2y$12$QgYDXvIWjbJeDJB.UUCMsehEB.0vUsQ5ZXCBLibyzFSRqmIKWCRRS',0,'LAB_TECHNICIAN','B+','FUSST-BB',1,'APPROVED','2026-11-15 19:20:24'),(13,'donor_demo','Demo Donor','donor.demo@example.test','03000000005','$2y$12$n.kr7CA0UiVAAJH4pdbeUek8DmseulX5kHMn1dZMjDNh3qTzlrDqK',0,'USER','O+','1',0,'APPROVED','2026-11-15 19:20:24'),(14,'mahira','mahir','mahir@gmail.com','03331232323','$2y$10$qdljOFW54yuzLQ7Lg1pNeeYwtZd3gV2z2Y1oIyeTsikz74OkAFL7K',0,'USER','B_POSITI','1',0,'APPROVED','2026-11-15 19:29:04');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'smart blood bank'
--

--
-- Dumping routines for database 'smart blood bank'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-16  1:06:24
