-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 11, 2026 at 11:12 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bsbt_general_tracker_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `action_type` enum('created','forwarded','action_taken','completed') DEFAULT 'action_taken',
  `department` enum('Administrative','Academe','Registrars') NOT NULL,
  `acted_by` varchar(100) NOT NULL,
  `action_title` varchar(255) NOT NULL,
  `remarks` text DEFAULT NULL,
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `attachments` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `document_id`, `action_type`, `department`, `acted_by`, `action_title`, `remarks`, `logged_at`, `attachments`) VALUES
(7, 3, 'created', 'Administrative', 'System Administrator', 'Communication Initiated', 'Dispatched from Administrative to Administrative. Recipient: sample', '2026-11-10 10:47:35', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `title`, `description`, `created_at`) VALUES
(2, 'Student Records', 'Manages student profiles, personal information, academic details, and enrollment records, providing an organized and centralized way to store, access, and maintain student data.', '2026-11-10 09:54:24');

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `ref_no` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `flow` enum('incoming','outgoing') DEFAULT 'incoming',
  `origin_department` enum('Administrative','Academe','Registrars') NOT NULL,
  `current_department` enum('Administrative','Academe','Registrars') NOT NULL,
  `current_holder` varchar(100) NOT NULL,
  `custody_route` text NOT NULL,
  `deadline` date NOT NULL,
  `status` enum('draft','pending','completed','overdue') DEFAULT 'pending',
  `attachment` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `attachments` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `documents`
--

INSERT INTO `documents` (`id`, `category_id`, `ref_no`, `title`, `flow`, `origin_department`, `current_department`, `current_holder`, `custody_route`, `deadline`, `status`, `attachment`, `created_at`, `attachments`) VALUES
(3, 2, 'DOC-2026-8152', 'sample document', 'incoming', 'Administrative', 'Administrative', 'sample', 'Administrative → Administrative', '2026-11-13', 'pending', '1794307655_Untitled.png', '2026-11-10 10:47:35', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ref_no` (`ref_no`),
  ADD KEY `category_id` (`category_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
