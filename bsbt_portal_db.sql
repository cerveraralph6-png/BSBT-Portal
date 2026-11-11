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
-- Database: `bsbt_portal_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `file_transfers`
--

CREATE TABLE `file_transfers` (
  `id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `status` enum('delivered','viewed','downloaded') DEFAULT 'delivered',
  `transferred_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tracker_audit_logs`
--

CREATE TABLE `tracker_audit_logs` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `action_title` varchar(255) NOT NULL,
  `action_details` varchar(255) NOT NULL,
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tracker_categories`
--

CREATE TABLE `tracker_categories` (
  `id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `is_archived` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tracker_categories`
--

INSERT INTO `tracker_categories` (`id`, `title`, `description`, `is_archived`, `created_at`) VALUES
(4, 'Student Clearance Records', 'End-of-term student clearances, department signatures, financial checks, and registry sign-offs.', 0, '2026-11-08 15:30:42'),
(5, 'Faculty Curriculum & Syllabi', 'Academic syllabus endorsements, course outlines, and instructional compliance communications.', 0, '2026-11-08 15:30:42'),
(6, 'Inter-Department Memos', 'Internal routing directives, administrative memos, campus advisories, and facility circulars.', 0, '2026-11-08 15:30:42');

-- --------------------------------------------------------

--
-- Table structure for table `tracker_documents`
--

CREATE TABLE `tracker_documents` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `ref_no` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `flow` enum('incoming','outgoing') DEFAULT 'incoming',
  `origin` varchar(150) DEFAULT NULL,
  `custody_received` varchar(100) DEFAULT NULL,
  `custody_forwarded` varchar(100) DEFAULT NULL,
  `custody_action` varchar(100) DEFAULT NULL,
  `deadline` date NOT NULL,
  `status` enum('pending','completed','overdue') DEFAULT 'pending',
  `attachment` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','academics','registrar') NOT NULL,
  `fullname` varchar(100) DEFAULT 'Master Administrator',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','suspended','archived','pending_approval') DEFAULT 'active',
  `is_archived` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `fullname`, `created_at`, `status`, `is_archived`) VALUES
(1, 'admin', '$2y$10$lhM2tZDi9glwJYtvHLCRHONzBjsphAmOSgZXr/PzdbWyaObAI0qly', 'admin', 'System Administrator', '2026-11-08 14:38:46', 'active', 0),
(2, 'a', '$2y$10$f0mNzK1pZuj7HxiuusvXcuzuy2vx3/DyJ0K0NR0pz29jS/RiQ0tI.', 'academics', 'a', '2026-11-10 11:45:48', 'archived', 1),
(3, 'b', '$2y$10$T6FmY2Ry4cP3oKHJeGyeM.kPEf.AKrSLVNck45wfX17tkBkRnp9VS', 'academics', 'b', '2026-11-10 14:08:30', 'active', 0),
(4, 'c', '$2y$10$J3FW3Qc3dIi9hWAhIYDSQeaoXSvsl1D472TrYz2ty4U/E/LKIzEbm', 'admin', 'c', '2026-11-10 15:35:46', 'active', 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_files`
--

CREATE TABLE `user_files` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_size` varchar(50) NOT NULL,
  `category` varchar(100) DEFAULT 'General',
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_files`
--

INSERT INTO `user_files` (`id`, `user_id`, `file_name`, `file_path`, `file_size`, `category`, `uploaded_at`) VALUES
(1, 1, 'Tekstong-biswal_aralin-4-2.pdf', '1794324786_153_Tekstong-biswal_aralin-4-2.pdf', '231 KB', 'Institutional Records', '2026-11-10 15:33:06');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `file_transfers`
--
ALTER TABLE `file_transfers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tracker_audit_logs`
--
ALTER TABLE `tracker_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`);

--
-- Indexes for table `tracker_categories`
--
ALTER TABLE `tracker_categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `tracker_documents`
--
ALTER TABLE `tracker_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `ref_no` (`ref_no`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_files`
--
ALTER TABLE `user_files`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `file_transfers`
--
ALTER TABLE `file_transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `tracker_audit_logs`
--
ALTER TABLE `tracker_audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tracker_categories`
--
ALTER TABLE `tracker_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `tracker_documents`
--
ALTER TABLE `tracker_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `user_files`
--
ALTER TABLE `user_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tracker_audit_logs`
--
ALTER TABLE `tracker_audit_logs`
  ADD CONSTRAINT `tracker_audit_logs_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `tracker_documents` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `tracker_documents`
--
ALTER TABLE `tracker_documents`
  ADD CONSTRAINT `tracker_documents_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `tracker_categories` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
