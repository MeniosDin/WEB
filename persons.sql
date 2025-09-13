-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3307
-- Generation Time: Sep 13, 2025 at 07:26 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `thesis_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `persons`
--

CREATE TABLE `persons` (
  `id` char(36) NOT NULL DEFAULT uuid(),
  `is_internal` tinyint(1) NOT NULL DEFAULT 1,
  `user_id` char(36) DEFAULT NULL,
  `first_name` varchar(80) NOT NULL,
  `last_name` varchar(80) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `affiliation` varchar(255) DEFAULT NULL,
  `role_category` enum('DEP','EEP','EDIP','ETEP','RESEARCH_A','RESEARCH_B','RESEARCH_C') NOT NULL,
  `has_phd` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `persons`
--

INSERT INTO `persons` (`id`, `is_internal`, `user_id`, `first_name`, `last_name`, `email`, `affiliation`, `role_category`, `has_phd`, `created_at`) VALUES
('f11d75b7-8e6d-11f0-8503-d8bbc1070448', 1, 'f11a1a41-8e6d-11f0-8503-d8bbc1070448', 'Καθηγητής', '', 'a.alpha@uni.gr', 'Department', 'DEP', 1, '2025-09-10 17:45:29'),
('f11fb1bf-8e6d-11f0-8503-d8bbc1070448', 1, 'f11a1be2-8e6d-11f0-8503-d8bbc1070448', 'Καθηγήτρια', '', 'b.beta@uni.gr', 'Department', 'DEP', 1, '2025-09-10 17:45:29'),
('f1274106-8e6d-11f0-8503-d8bbc1070448', 1, 'f11a1c50-8e6d-11f0-8503-d8bbc1070448', 'Καθηγητής', '', 'g.gamma@uni.gr', 'Department', 'DEP', 1, '2025-09-10 17:45:29'),
('f12884f1-8e6d-11f0-8503-d8bbc1070448', 1, 'f11a1ca7-8e6d-11f0-8503-d8bbc1070448', 'Καθηγητής', '', 'd.delta@uni.gr', 'Department', 'DEP', 1, '2025-09-10 17:45:29'),
('f129bf7c-8e6d-11f0-8503-d8bbc1070448', 1, 'f11a1cfa-8e6d-11f0-8503-d8bbc1070448', 'Καθηγήτρια', '', 'e.epsilon@uni.gr', 'Department', 'DEP', 1, '2025-09-10 17:45:29');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `persons`
--
ALTER TABLE `persons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_person_email` (`email`),
  ADD UNIQUE KEY `uq_person_user` (`user_id`);

--
-- Constraints for dumped tables
--

--
-- Constraints for table `persons`
--
ALTER TABLE `persons`
  ADD CONSTRAINT `fk_person_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
