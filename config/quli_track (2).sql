-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 14, 2026 at 10:25 PM
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
-- Database: `quli_track`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_history`
--

CREATE TABLE `activity_history` (
  `id` int(11) NOT NULL,
  `snapshot_date` date NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_name` varchar(255) NOT NULL,
  `user_role` enum('admin','developer','qa','client') NOT NULL,
  `projects_added` int(11) DEFAULT 0,
  `clients_added` int(11) DEFAULT 0,
  `test_cases_executed` int(11) DEFAULT 0,
  `bugs_resolved` int(11) DEFAULT 0,
  `test_cases_added` int(11) DEFAULT 0,
  `bugs_reported` int(11) DEFAULT 0,
  `bugs_closed` int(11) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `bugs_open` int(11) DEFAULT 0,
  `bugs_in_progress` int(11) DEFAULT 0,
  `bugs_reopened` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_history`
--

INSERT INTO `activity_history` (`id`, `snapshot_date`, `user_id`, `user_name`, `user_role`, `projects_added`, `clients_added`, `test_cases_executed`, `bugs_resolved`, `test_cases_added`, `bugs_reported`, `bugs_closed`, `created_at`, `bugs_open`, `bugs_in_progress`, `bugs_reopened`) VALUES
(53, '2026-05-13', 1, 'Shubham', 'admin', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 00:57:53', 0, 0, 0),
(54, '2026-05-13', 2, 'Aman Gupta', 'developer', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 00:57:53', 0, 0, 0),
(55, '2026-05-13', 4, 'Jay Singh', 'qa', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 00:57:53', 0, 0, 0),
(56, '2026-05-13', 8, 'Raj Gupta', 'qa', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 00:57:53', 0, 0, 0),
(57, '2026-05-13', 9, 'Ayush Jain', 'developer', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 00:57:53', 0, 0, 0),
(58, '2026-05-13', 10, 'Jatan Rajpoot', 'qa', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 00:57:53', 0, 0, 0),
(59, '2026-05-13', 11, 'Jatan Varma', 'developer', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 00:57:53', 0, 0, 0),
(60, '2026-05-13', 12, 'Shurti Dangi', 'qa', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 00:57:53', 0, 0, 0),
(61, '2026-05-13', 13, 'Janhvi Rajpoot', 'developer', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 00:57:53', 0, 0, 0),
(62, '2026-05-13', 14, 'Deepak Sen', 'qa', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 00:57:53', 0, 0, 0),
(63, '2026-05-13', 15, 'Deepesh Lodhi', 'developer', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 00:57:53', 0, 0, 0),
(64, '2026-05-13', 16, 'Paras Dixit', 'developer', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 00:57:53', 0, 0, 0),
(65, '2026-05-13', 17, 'Sachin Dubey', 'qa', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 00:57:53', 0, 0, 0),
(66, '2026-05-14', 1, 'Shubham', 'admin', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 01:13:43', 0, 0, 0),
(67, '2026-05-14', 2, 'Aman Gupta', 'developer', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 01:13:43', 0, 0, 0),
(68, '2026-05-14', 4, 'Jay Singh', 'qa', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 01:13:43', 0, 0, 0),
(69, '2026-05-14', 8, 'Raj Gupta', 'qa', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 01:13:43', 0, 0, 0),
(70, '2026-05-14', 9, 'Ayush Jain', 'developer', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 01:13:43', 0, 0, 0),
(71, '2026-05-14', 10, 'Jatan Rajpoot', 'qa', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 01:13:43', 0, 0, 0),
(72, '2026-05-14', 11, 'Jatan Varma', 'developer', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 01:13:43', 0, 0, 0),
(73, '2026-05-14', 12, 'Shurti Dangi', 'qa', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 01:13:44', 0, 0, 0),
(74, '2026-05-14', 13, 'Janhvi Rajpoot', 'developer', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 01:13:44', 0, 0, 0),
(75, '2026-05-14', 14, 'Deepak Sen', 'qa', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 01:13:44', 0, 0, 0),
(76, '2026-05-14', 15, 'Deepesh Lodhi', 'developer', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 01:13:44', 0, 0, 0),
(77, '2026-05-14', 16, 'Paras Dixit', 'developer', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 01:13:44', 0, 0, 0),
(78, '2026-05-14', 17, 'Sachin Dubey', 'qa', 0, 0, 0, 0, 0, 0, 0, '2026-05-14 01:13:44', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `bugs`
--

CREATE TABLE `bugs` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `reported_by` int(11) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `status` enum('open','in_progress','resolved','closed','reopened') DEFAULT 'open',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `type` enum('corporate','individual','government') NOT NULL DEFAULT 'corporate',
  `contact_person` varchar(150) NOT NULL,
  `email` varchar(200) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `website` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  `updatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`id`, `name`, `type`, `contact_person`, `email`, `phone`, `website`, `status`, `created_by`, `createdAt`, `updatedAt`) VALUES
(1, 'Ram', 'corporate', 'Ram', 'ram@gmail.com', '8656666464', '', 'active', 1, '2026-04-08 11:55:50', '2026-05-13 11:29:32'),
(2, 'shubham Kankane', 'individual', 'Shubh', 'shubh@gmail.com', '6985533589', '', 'active', 1, '2026-04-08 17:54:49', '2026-05-13 11:29:32'),
(3, 'Demo Client', 'individual', 'Anuj', 'anuj@gmail.com', '7996999954', '', 'active', 1, '2026-05-13 11:11:25', '2026-05-13 11:29:32'),
(4, 'Demo Client1', 'government', 'Client1', 'client@gmail.com', '9979797979', '', 'active', NULL, '2026-05-13 14:51:49', '2026-05-13 14:51:49'),
(5, 'Demo Client2', 'corporate', 'Client2', 'client2@gmail.com', '9566566496', '', 'active', NULL, '2026-05-13 14:54:08', '2026-05-13 14:54:08'),
(6, 'Demo Client3', 'individual', 'Client3', 'client3@gmail.com', '7796645616', '', 'active', NULL, '2026-05-13 14:55:14', '2026-05-13 14:55:14'),
(7, 'Demo Client4', 'government', 'Client4', 'client4@gmail.com', '7956599988', '', 'active', NULL, '2026-05-13 14:55:53', '2026-05-13 14:55:53'),
(8, 'Demo Client5', 'individual', 'Client5', 'client5@gmail.com', '6897449466', '', 'active', NULL, '2026-05-13 14:57:19', '2026-05-13 14:57:19'),
(9, 'Demo Client6', 'government', 'Client6', 'client6@gmail.com', '8964764979', '', 'active', NULL, '2026-05-13 14:58:00', '2026-05-13 14:58:00'),
(10, 'Demo Client7', 'individual', 'Client7', 'client7@gmail.com', '7966499654', '', 'active', NULL, '2026-05-13 14:59:07', '2026-05-13 15:44:57'),
(11, 'Demo Client8', 'corporate', 'Client8', 'client8@gmail.com', '9799999446', '', 'active', NULL, '2026-05-13 14:59:49', '2026-05-13 15:44:42');

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `client_id` int(11) NOT NULL,
  `status` enum('Not Started','In Progress','Completed') NOT NULL DEFAULT 'Not Started',
  `action` enum('active','inactive') DEFAULT 'active',
  `project_lead_id` int(11) DEFAULT NULL,
  `qa_lead_id` int(11) DEFAULT NULL,
  `start_date` date NOT NULL,
  `deadline_date` date NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  `updatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`id`, `name`, `client_id`, `status`, `action`, `project_lead_id`, `qa_lead_id`, `start_date`, `deadline_date`, `delivery_date`, `created_by`, `createdAt`, `updatedAt`) VALUES
(2, 'Demo', 2, 'Not Started', 'active', 2, 4, '2026-04-08', '2026-05-05', '2026-04-08', 1, '2026-04-08 17:55:52', '2026-05-13 11:29:32'),
(3, 'Demo1', 1, 'Completed', 'inactive', 2, 4, '2026-04-10', '2026-05-10', '2026-05-11', 1, '2026-04-09 11:23:42', '2026-05-14 17:15:42'),
(4, 'Demo2', 1, 'In Progress', 'active', 2, 4, '2026-05-16', '2026-06-16', NULL, 1, '2026-05-13 01:28:07', '2026-05-13 11:29:32'),
(5, 'Demo3', 3, 'In Progress', 'active', 11, 4, '2026-05-13', '2026-06-15', NULL, 1, '2026-05-13 11:13:45', '2026-05-14 17:05:47');

-- --------------------------------------------------------

--
-- Table structure for table `project_backend_devs`
--

CREATE TABLE `project_backend_devs` (
  `project_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `project_backend_devs`
--

INSERT INTO `project_backend_devs` (`project_id`, `user_id`) VALUES
(2, 2),
(2, 9),
(3, 9),
(4, 2),
(5, 9),
(5, 15);

-- --------------------------------------------------------

--
-- Table structure for table `project_edit_requests`
--

CREATE TABLE `project_edit_requests` (
  `id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `requested_by` int(10) UNSIGNED NOT NULL,
  `status` enum('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `old_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`old_data`)),
  `new_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`new_data`)),
  `admin_note` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_frontend_devs`
--

CREATE TABLE `project_frontend_devs` (
  `project_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `project_frontend_devs`
--

INSERT INTO `project_frontend_devs` (`project_id`, `user_id`) VALUES
(2, 2),
(2, 9),
(3, 9),
(4, 2),
(5, 11),
(5, 15);

-- --------------------------------------------------------

--
-- Table structure for table `project_qa_team`
--

CREATE TABLE `project_qa_team` (
  `project_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `project_qa_team`
--

INSERT INTO `project_qa_team` (`project_id`, `user_id`) VALUES
(2, 4),
(4, 8),
(5, 10),
(5, 14);

-- --------------------------------------------------------

--
-- Table structure for table `project_technologies`
--

CREATE TABLE `project_technologies` (
  `project_id` int(11) NOT NULL,
  `tech_id` int(11) NOT NULL,
  `tech_role` enum('frontend','backend','other') NOT NULL DEFAULT 'other'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `project_technologies`
--

INSERT INTO `project_technologies` (`project_id`, `tech_id`, `tech_role`) VALUES
(2, 1, 'frontend'),
(2, 2, 'backend'),
(2, 3, 'other'),
(3, 1, 'frontend'),
(3, 2, 'backend'),
(3, 3, 'other'),
(4, 1, 'backend'),
(4, 2, 'frontend'),
(4, 3, 'other'),
(5, 1, 'backend'),
(5, 2, 'frontend'),
(5, 3, 'other');

-- --------------------------------------------------------

--
-- Table structure for table `requirements`
--

CREATE TABLE `requirements` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `reported_date` date DEFAULT NULL,
  `expected_delivery` date DEFAULT NULL,
  `actual_delivery` date DEFAULT NULL,
  `is_developed` tinyint(1) DEFAULT 0,
  `is_tested` tinyint(1) DEFAULT 0,
  `is_delivered` tinyint(1) DEFAULT 0,
  `uat_done` tinyint(1) DEFAULT 0,
  `bug_after_uat` tinyint(1) DEFAULT 0,
  `bug_fixed` tinyint(1) DEFAULT 0,
  `status` enum('open','in_progress','completed','closed') DEFAULT 'open',
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `requirements`
--

INSERT INTO `requirements` (`id`, `project_id`, `title`, `description`, `priority`, `reported_date`, `expected_delivery`, `actual_delivery`, `is_developed`, `is_tested`, `is_delivered`, `uat_done`, `bug_after_uat`, `bug_fixed`, `status`, `createdAt`) VALUES
(3, 2, 'login page', 'login', 'high', '2026-04-09', NULL, NULL, 1, 1, 1, 0, 0, 0, 'open', '2026-04-09 05:45:06'),
(5, 3, 'Sample Requirement', 'This is a sample description', 'medium', '2026-04-09', NULL, NULL, 1, 1, 1, 0, 0, 0, 'open', '2026-04-09 06:07:03'),
(6, 2, 'user page', 'user page', 'high', '2026-04-15', NULL, NULL, 1, 1, 1, 0, 0, 0, 'open', '2026-04-15 10:08:51'),
(7, 3, 'KUch bhi', 'ckcmkmcasc', 'critical', '2026-05-13', '2027-06-13', '2026-06-14', 0, 0, 0, 0, 0, 0, 'open', '2026-05-12 23:43:03'),
(8, 4, 'KUch bhi', 'c m cj kmskmkm', 'low', '2026-05-13', NULL, NULL, 1, 1, 1, 0, 0, 0, 'open', '2026-05-12 23:44:19'),
(9, 5, 'login page 2', 'cnasichih', 'high', '2026-05-14', NULL, NULL, 1, 0, 0, 0, 0, 0, 'open', '2026-05-14 11:34:20'),
(10, 2, 'Kuch bhi 2', 'scslacaoj', 'low', '2026-05-14', NULL, NULL, 1, 0, 0, 0, 0, 0, 'open', '2026-05-14 12:30:31');

-- --------------------------------------------------------

--
-- Table structure for table `team_edit_requests`
--

CREATE TABLE `team_edit_requests` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `project_lead_id` int(11) DEFAULT NULL,
  `qa_lead_id` int(11) DEFAULT NULL,
  `fe_devs` text DEFAULT NULL COMMENT 'JSON array of user IDs',
  `be_devs` text DEFAULT NULL COMMENT 'JSON array of user IDs',
  `qa_team` text DEFAULT NULL COMMENT 'JSON array of user IDs',
  `status` enum('pending','accepted','rejected') DEFAULT 'pending',
  `admin_reply` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `technologies`
--

CREATE TABLE `technologies` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` enum('frontend','backend','database','testing','other') NOT NULL DEFAULT 'other',
  `createdAt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `technologies`
--

INSERT INTO `technologies` (`id`, `name`, `category`, `createdAt`) VALUES
(1, 'PHP', 'frontend', '2026-04-08 17:01:16'),
(2, 'HTML', 'backend', '2026-04-08 17:01:58'),
(3, 'SQL', 'database', '2026-04-08 17:02:31');

-- --------------------------------------------------------

--
-- Table structure for table `testing_types`
--

CREATE TABLE `testing_types` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `testing_types`
--

INSERT INTO `testing_types` (`id`, `name`, `description`, `created_at`) VALUES
(1, 'Manual testing', '', '2026-04-09 09:29:13'),
(2, 'Automation Testing', 'Automate', '2026-04-09 18:31:58');

-- --------------------------------------------------------

--
-- Table structure for table `test_cases`
--

CREATE TABLE `test_cases` (
  `id` int(11) NOT NULL,
  `tc_custom_id` varchar(50) DEFAULT NULL,
  `project_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `page_name` varchar(255) NOT NULL,
  `category` varchar(50) DEFAULT 'Functional',
  `requirement` varchar(255) DEFAULT '',
  `description` text DEFAULT NULL,
  `pre_conditions` text DEFAULT NULL,
  `test_actions` text DEFAULT NULL,
  `expected_result` text DEFAULT NULL,
  `actual_result` text DEFAULT NULL,
  `is_executed` tinyint(1) DEFAULT 0,
  `executed_on` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('Not tested','Pass','Fail') DEFAULT 'Not tested',
  `bug_raised` tinyint(1) DEFAULT 0,
  `bug_status` enum('Open','In Progress','Resolved','Closed','Reopen') DEFAULT NULL,
  `bug_screenshots` varchar(255) DEFAULT NULL,
  `bug_videos` text DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `fe_dev_id` int(11) DEFAULT NULL,
  `be_dev_id` int(11) DEFAULT NULL,
  `executed_by_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `is_automated` tinyint(1) DEFAULT 0,
  `test_data` varchar(255) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `bug_opened_at` timestamp NULL DEFAULT NULL,
  `bug_in_progress_at` timestamp NULL DEFAULT NULL,
  `bug_resolved_at` timestamp NULL DEFAULT NULL,
  `bug_closed_at` timestamp NULL DEFAULT NULL,
  `bug_reopened_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `test_cases`
--

INSERT INTO `test_cases` (`id`, `tc_custom_id`, `project_id`, `title`, `page_name`, `category`, `requirement`, `description`, `pre_conditions`, `test_actions`, `expected_result`, `actual_result`, `is_executed`, `executed_on`, `status`, `bug_raised`, `bug_status`, `bug_screenshots`, `bug_videos`, `assigned_to`, `fe_dev_id`, `be_dev_id`, `executed_by_id`, `created_by`, `is_automated`, `test_data`, `comments`, `created_at`, `bug_opened_at`, `bug_in_progress_at`, `bug_resolved_at`, `bug_closed_at`, `bug_reopened_at`) VALUES
(1, 'tc1', 3, 'sssacvfbf', 'bgfbf', 'Functional', 'Sample Requirement', 'fbfdbdfbd', 'fbfbfd', 'bgfgffbfb', 'dbfbvdf', 'dsbdvdv', 1, '2026-05-13 20:50:36', 'Fail', 1, 'In Progress', '[\"scr_69f080ef4ab790.10446429.png\",\"scr_69fccd8f7f5d12.10251179.png\"]', '[\"vid_69f1f6f6250389.02604668.mp4\"]', 2, NULL, NULL, 1, 1, 0, NULL, '0', '2026-04-28 09:42:07', NULL, NULL, NULL, NULL, NULL),
(2, 'tc55', 3, 'ndvncvncsvni', 'dvndcvndcvnd', 'Functional', 'Sample Requirement', 'dvmckvnhchbvsugh', 'bjofbhjognjo', 'fjobfi0objh[hjbrij', 'bngknjighnjij', 'bngfnjigfnjfigj', 1, '2026-05-13 20:50:31', 'Fail', 1, 'Resolved', '[\"scr_69fd928b1e3d24.11706941.png\"]', '[\"vid_6a00b48d505730.35735705.mp4\"]', 2, NULL, NULL, 1, 1, 0, NULL, '', '2026-05-08 07:36:43', NULL, NULL, NULL, NULL, NULL),
(3, 'TC-001', 3, 'dvsdhdrh', 'vsds', 'Functional', 'Sample Requirement', '', '', '', 'csvsdvsdd', '', 0, '2026-05-12 10:17:23', 'Not tested', 0, 'Open', '[]', '[]', NULL, NULL, NULL, NULL, 1, 0, NULL, '', '2026-05-12 10:17:23', NULL, NULL, NULL, NULL, NULL),
(4, 'TC-002', 3, 'nlvknakvnh', 'zxlnzlvn', 'Functional', 'Sample Requirement', 'oshiavih', '', '', 'hiouvhiuvg', '', 1, '2026-05-13 11:26:08', 'Not tested', 0, 'Open', '[]', '[]', NULL, NULL, NULL, 1, 1, 0, NULL, '', '2026-05-12 10:17:56', NULL, NULL, NULL, NULL, NULL),
(5, 'TC-003', 3, 'asfafasfva', 'sfwegwe', 'Functional', 'Sample Requirement', '', '', '', 'caggfva', '', 1, '2026-05-13 10:21:50', 'Pass', 0, 'Open', '[]', '[]', NULL, NULL, NULL, 2, 1, 0, NULL, '0', '2026-05-12 10:18:21', NULL, NULL, NULL, NULL, NULL),
(6, 'sfegergr', 2, 'sgewgwweg', 'dgreygerg', 'Functional', 'user page', 'sfaFeF', 'sfasFAEF', '', 'scfasfasf', '', 1, '2026-05-14 08:54:20', 'Fail', 1, 'Reopen', '[]', '[]', NULL, NULL, NULL, 1, 1, 0, NULL, '0', '2026-05-12 10:18:59', NULL, NULL, NULL, NULL, NULL),
(7, 'TC-001', 2, 'vksvnsvhi', ', nkn x nk n', 'Functional', 'user page', 'kxbnkj;ab', 'ichbiuabh', '', 'hasiohb;', '', 1, '2026-05-14 08:54:11', 'Fail', 1, 'Closed', '[]', '[]', NULL, NULL, NULL, 1, 1, 0, NULL, '', '2026-05-12 10:21:03', NULL, NULL, NULL, NULL, NULL),
(8, 'TC-002', 2, 'skgvsvhsvhsih', 'ikvnsvsvhvnn', 'Functional', 'user page', 'jv jv bjsbvbsvsvhi', 'nsb bsbs j bn', '', 'nvsvnsnb', '', 1, '2026-05-14 08:53:59', 'Fail', 1, 'Resolved', '[]', '[]', NULL, NULL, NULL, 1, 1, 0, NULL, '', '2026-05-12 10:32:07', NULL, NULL, NULL, NULL, NULL),
(9, 'TC-003', 2, 'abcd', 'login page', 'UI', 'user page', 'abcde', 'abcde', 'da', 'abcde', 'sd', 1, '2026-05-14 08:53:40', 'Pass', 1, 'In Progress', '[]', '[]', NULL, NULL, NULL, 1, 1, 0, NULL, '', '2026-05-12 10:33:56', NULL, NULL, NULL, NULL, NULL),
(10, 'TC-004', 2, 'cvlcxbvxjk', 'lxvnmzdvnhzn', 'Functional', 'login page', 'kcvnskvhn', 'kdnvkshvqq', 'jsvbhvshbgu', 'dnvdskvn', 'bnvkjsbv', 1, '2026-05-14 11:28:55', 'Fail', 1, 'Closed', '[]', '[]', 2, NULL, NULL, 9, 1, 0, NULL, '', '2026-05-12 10:35:14', NULL, NULL, NULL, NULL, NULL),
(11, 'TC-004', 3, 'clafvjk', 'djovnskvnj', 'Functional', 'Sample Requirement', 'jnidvjonsi', 'nvnsijj', 'scknaschnaihc', 'jjvnsij', 'jjvnsij', 1, '2026-05-13 10:21:48', 'Pass', 0, 'Open', '[]', '[]', NULL, NULL, NULL, 2, 1, 0, NULL, '0', '2026-05-12 12:28:16', NULL, NULL, NULL, NULL, NULL),
(12, 'TC-005', 3, 'xvljdsjvjo', 'jdsjovsojvo', 'Non-Functional', 'KUch bhi', 'fbfjobo', 'jodvjo', 'knbkb', 'ojvowj', 'jdovj', 1, '2026-05-13 20:52:41', 'Fail', 0, 'Open', '[]', '[]', NULL, NULL, NULL, 4, 1, 0, NULL, '0', '2026-05-13 06:25:30', NULL, NULL, NULL, NULL, NULL),
(13, 'TC-006', 3, 'vxlvvjdvj', 'nskndakhvn', 'Functional', 'KUch bhi', 'kncsjcbsjdb', 'kscbacbab', 'ndvndsjvbjg', 'najcbjb', 'jscbjab', 1, '2026-05-13 20:53:53', 'Pass', 1, 'In Progress', '[\"scr_6a0462c9c7ef95.91581854.png\"]', '[]', 2, NULL, NULL, 1, 1, 0, NULL, '', '2026-05-13 11:38:49', NULL, NULL, NULL, NULL, NULL),
(14, 'TC-007', 3, 'xzxvadf', 'bfndh', 'UI', 'KUch bhi', 'caspckajc', 'jwofjqj', 'dmvksnvn', 'ofjoqjco', 'ojqojo', 1, '2026-05-13 20:59:23', 'Fail', 1, 'Open', '[\"scr_6a04e61c127839.49924451.png\",\"scr_6a04e61c1339f7.05602165.png\"]', '[]', NULL, NULL, NULL, 1, 1, 0, NULL, '', '2026-05-13 20:59:08', NULL, NULL, NULL, NULL, NULL),
(15, 'TC-008', 3, 'VSVDSSGS', 'vss', 'Functional', 'KUch bhi', 'dvsdv', 'sdvdsvsd', 'dsgdsgsdg', 'dsbsdbs', '', 1, '2026-05-14 10:12:43', 'Not tested', 0, 'Open', '[]', '[]', 9, NULL, NULL, 1, 1, 0, NULL, '', '2026-05-13 21:46:40', NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `test_plans`
--

CREATE TABLE `test_plans` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `objective` text DEFAULT NULL,
  `scope` text DEFAULT NULL,
  `testing_types` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of strings' CHECK (json_valid(`testing_types`)),
  `technologies` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of strings' CHECK (json_valid(`technologies`)),
  `project_lead_id` int(11) DEFAULT NULL,
  `test_lead_id` int(11) DEFAULT NULL,
  `roles_covered` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `test_plans`
--

INSERT INTO `test_plans` (`id`, `project_id`, `title`, `objective`, `scope`, `testing_types`, `technologies`, `project_lead_id`, `test_lead_id`, `roles_covered`, `created_at`) VALUES
(2, 2, 'vcja', 'kha', 'knv ak', '[\"Manual testing\",\"Automation testing\",\"Functional testing\"]', '[]', 15, 4, 'mxk nkh', '2026-05-13 13:41:13');

-- --------------------------------------------------------

--
-- Table structure for table `test_plan_edit_requests`
--

CREATE TABLE `test_plan_edit_requests` (
  `id` int(11) NOT NULL,
  `test_plan_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `objective` text DEFAULT NULL,
  `scope` text DEFAULT NULL,
  `testing_types` text DEFAULT NULL COMMENT 'JSON array',
  `technologies` text DEFAULT NULL COMMENT 'JSON array',
  `project_lead_id` int(11) DEFAULT NULL,
  `test_lead_id` int(11) DEFAULT NULL,
  `roles_covered` text DEFAULT NULL,
  `status` enum('pending','accepted','rejected') DEFAULT 'pending',
  `admin_reply` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `username` varchar(200) NOT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','developer','qa','lead','client') NOT NULL DEFAULT 'developer',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `createdAt` datetime NOT NULL DEFAULT current_timestamp(),
  `updatedAt` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `username`, `phone`, `password`, `role`, `status`, `createdAt`, `updatedAt`, `created_at`) VALUES
(1, 'Shubham', 'admin@gmail.com', '', 'e64b78fc3bc91bcbc7dc232ba8ec59e0', 'admin', 'active', '2026-04-08 11:41:03', '2026-05-11 13:01:53', '2026-05-13 03:33:06'),
(2, 'Aman Gupta', 'aman@gmail.com', '8569874523', '73b25522615dac9cfd289ee35faef4ef', 'developer', 'active', '2026-04-08 11:52:26', '2026-05-12 19:44:57', '2026-05-13 03:33:06'),
(4, 'Jay Singh', 'shubhamkankane31@gmail.com', '7894561235', 'cd2e5239aa2ec4bba574bba4dbe6543c', 'qa', 'active', '2026-04-08 11:54:40', '2026-05-13 01:58:14', '2026-05-13 03:33:06'),
(8, 'Raj Gupta', 'raj@gmail.com', '8795236685', 'f8d53959da9bc156492d1a3f66e5c9d1', 'qa', 'active', '2026-05-13 01:58:00', '2026-05-13 01:58:00', '2026-05-13 03:33:06'),
(9, 'Ayush Jain', 'ayush@gmail.com', '6996996116', '082b17c053274c462139ef53fe270780', 'developer', 'active', '2026-05-13 02:15:42', '2026-05-13 02:15:42', '2026-05-13 03:33:06'),
(10, 'Jatan Rajpoot', 'jatan@gmail.com', '6896464641', '9faf0f81dc4d919854f88c7cce24dff8', 'qa', 'active', '2026-05-13 02:16:47', '2026-05-13 02:16:47', '2026-05-13 03:33:06'),
(11, 'Jatan Varma', 'jatanv@gmail.com', '7977799656', '9faf0f81dc4d919854f88c7cce24dff8', 'developer', 'active', '2026-05-13 02:17:57', '2026-05-13 02:17:57', '2026-05-13 03:33:06'),
(12, 'Shurti Dangi', 'shurti@gmail.com', '7669656996', '3fdc04024d30554abed7af2ac87071d8', 'qa', 'active', '2026-05-13 02:19:20', '2026-05-13 02:19:20', '2026-05-13 03:33:06'),
(13, 'Janhvi Rajpoot', 'janhvi@gmail.com', '7849659964', '1179f2afefd13268ed549cd2d7919a76', 'developer', 'active', '2026-05-13 02:20:38', '2026-05-14 17:06:52', '2026-05-13 03:33:06'),
(14, 'Deepak Sen', 'deepak@gmail.com', '8654964444', 'fcc9a215009de59c85c88ae3bbcfd7e9', 'qa', 'active', '2026-05-13 02:22:19', '2026-05-13 02:45:24', '2026-05-13 03:33:06'),
(15, 'Deepesh Lodhi', 'deepesh@gmail.com', '9594646298', 'd1a2a95ee9e5aeb925b90de00294e01d', 'developer', 'active', '2026-05-13 02:23:20', '2026-05-13 02:23:20', '2026-05-13 03:33:06'),
(16, 'Paras Dixit', 'paras@gmail.com', '9649461648', 'dcc75898b1f35348fe67b64f520f99f9', 'developer', 'active', '2026-05-13 02:35:53', '2026-05-13 03:56:48', '2026-05-13 03:33:06'),
(17, 'Sachin Dubey', 'sachin@gmail.com', '8664963699', '1455494c9f58563769b601366047c030', 'qa', 'active', '2026-05-13 04:44:04', '2026-05-14 17:17:23', '2026-05-13 04:44:04');

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_projects_full`
-- (See below for the actual view)
--
CREATE TABLE `vw_projects_full` (
`id` int(11)
,`project_name` varchar(150)
,`client_name` varchar(150)
,`status` enum('Not Started','In Progress','Completed')
,`project_lead` varchar(150)
,`qa_lead` varchar(150)
,`start_date` date
,`deadline_date` date
,`delivery_date` date
,`frontend_devs` mediumtext
,`backend_devs` mediumtext
,`qa_team` mediumtext
,`frontend_tech` mediumtext
,`backend_tech` mediumtext
,`other_tech` mediumtext
,`createdAt` datetime
);

-- --------------------------------------------------------

--
-- Structure for view `vw_projects_full`
--
DROP TABLE IF EXISTS `vw_projects_full`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_projects_full`  AS SELECT `p`.`id` AS `id`, `p`.`name` AS `project_name`, `c`.`name` AS `client_name`, `p`.`status` AS `status`, `ul`.`name` AS `project_lead`, `uq`.`name` AS `qa_lead`, `p`.`start_date` AS `start_date`, `p`.`deadline_date` AS `deadline_date`, `p`.`delivery_date` AS `delivery_date`, group_concat(distinct case when `pfd`.`user_id` is not null then `ufd`.`name` end order by `ufd`.`name` ASC separator ', ') AS `frontend_devs`, group_concat(distinct case when `pbd`.`user_id` is not null then `ubd`.`name` end order by `ubd`.`name` ASC separator ', ') AS `backend_devs`, group_concat(distinct case when `pqt`.`user_id` is not null then `uqt`.`name` end order by `uqt`.`name` ASC separator ', ') AS `qa_team`, group_concat(distinct case when `pt`.`tech_role` = 'frontend' then `t`.`name` end order by `t`.`name` ASC separator ', ') AS `frontend_tech`, group_concat(distinct case when `pt`.`tech_role` = 'backend' then `t`.`name` end order by `t`.`name` ASC separator ', ') AS `backend_tech`, group_concat(distinct case when `pt`.`tech_role` = 'other' then `t`.`name` end order by `t`.`name` ASC separator ', ') AS `other_tech`, `p`.`createdAt` AS `createdAt` FROM (((((((((((`projects` `p` left join `clients` `c` on(`c`.`id` = `p`.`client_id`)) left join `users` `ul` on(`ul`.`id` = `p`.`project_lead_id`)) left join `users` `uq` on(`uq`.`id` = `p`.`qa_lead_id`)) left join `project_frontend_devs` `pfd` on(`pfd`.`project_id` = `p`.`id`)) left join `users` `ufd` on(`ufd`.`id` = `pfd`.`user_id`)) left join `project_backend_devs` `pbd` on(`pbd`.`project_id` = `p`.`id`)) left join `users` `ubd` on(`ubd`.`id` = `pbd`.`user_id`)) left join `project_qa_team` `pqt` on(`pqt`.`project_id` = `p`.`id`)) left join `users` `uqt` on(`uqt`.`id` = `pqt`.`user_id`)) left join `project_technologies` `pt` on(`pt`.`project_id` = `p`.`id`)) left join `technologies` `t` on(`t`.`id` = `pt`.`tech_id`)) GROUP BY `p`.`id` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_history`
--
ALTER TABLE `activity_history`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unq_snapshot_user` (`snapshot_date`,`user_id`),
  ADD KEY `idx_snapshot_date` (`snapshot_date`),
  ADD KEY `idx_user_role` (`user_role`),
  ADD KEY `fk_ah_user` (`user_id`);

--
-- Indexes for table `bugs`
--
ALTER TABLE `bugs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_bug_project` (`project_id`),
  ADD KEY `fk_bug_reported` (`reported_by`),
  ADD KEY `fk_bug_assigned` (`assigned_to`),
  ADD KEY `fk_bug_resolved` (`resolved_by`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_clients_email` (`email`),
  ADD KEY `fk_clients_created_by` (`created_by`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_project_client` (`client_id`),
  ADD KEY `fk_project_lead` (`project_lead_id`),
  ADD KEY `fk_project_qa_lead` (`qa_lead_id`),
  ADD KEY `fk_projects_created_by` (`created_by`);

--
-- Indexes for table `project_backend_devs`
--
ALTER TABLE `project_backend_devs`
  ADD PRIMARY KEY (`project_id`,`user_id`),
  ADD KEY `fk_bedev_user` (`user_id`);

--
-- Indexes for table `project_edit_requests`
--
ALTER TABLE `project_edit_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_user` (`requested_by`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `project_frontend_devs`
--
ALTER TABLE `project_frontend_devs`
  ADD PRIMARY KEY (`project_id`,`user_id`),
  ADD KEY `fk_fedev_user` (`user_id`);

--
-- Indexes for table `project_qa_team`
--
ALTER TABLE `project_qa_team`
  ADD PRIMARY KEY (`project_id`,`user_id`),
  ADD KEY `fk_qa_user` (`user_id`);

--
-- Indexes for table `project_technologies`
--
ALTER TABLE `project_technologies`
  ADD PRIMARY KEY (`project_id`,`tech_id`,`tech_role`),
  ADD KEY `fk_ptech_tech` (`tech_id`);

--
-- Indexes for table `requirements`
--
ALTER TABLE `requirements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `team_edit_requests`
--
ALTER TABLE `team_edit_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `technologies`
--
ALTER TABLE `technologies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_tech_name` (`name`);

--
-- Indexes for table `testing_types`
--
ALTER TABLE `testing_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `test_cases`
--
ALTER TABLE `test_cases`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `idx_tc_custom_project` (`tc_custom_id`,`project_id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `assigned_to` (`assigned_to`);

--
-- Indexes for table `test_plans`
--
ALTER TABLE `test_plans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `project_lead_id` (`project_lead_id`);

--
-- Indexes for table `test_plan_edit_requests`
--
ALTER TABLE `test_plan_edit_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_history`
--
ALTER TABLE `activity_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=79;

--
-- AUTO_INCREMENT for table `bugs`
--
ALTER TABLE `bugs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `project_edit_requests`
--
ALTER TABLE `project_edit_requests`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `requirements`
--
ALTER TABLE `requirements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `team_edit_requests`
--
ALTER TABLE `team_edit_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `technologies`
--
ALTER TABLE `technologies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `testing_types`
--
ALTER TABLE `testing_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `test_cases`
--
ALTER TABLE `test_cases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `test_plans`
--
ALTER TABLE `test_plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `test_plan_edit_requests`
--
ALTER TABLE `test_plan_edit_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_history`
--
ALTER TABLE `activity_history`
  ADD CONSTRAINT `fk_ah_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `bugs`
--
ALTER TABLE `bugs`
  ADD CONSTRAINT `fk_bug_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_bug_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_bug_reported` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_bug_resolved` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `clients`
--
ALTER TABLE `clients`
  ADD CONSTRAINT `fk_clients_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `fk_project_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_project_lead` FOREIGN KEY (`project_lead_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_project_qa_lead` FOREIGN KEY (`qa_lead_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_projects_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `project_backend_devs`
--
ALTER TABLE `project_backend_devs`
  ADD CONSTRAINT `fk_bedev_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_bedev_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `project_frontend_devs`
--
ALTER TABLE `project_frontend_devs`
  ADD CONSTRAINT `fk_fedev_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_fedev_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `project_qa_team`
--
ALTER TABLE `project_qa_team`
  ADD CONSTRAINT `fk_qa_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_qa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `project_technologies`
--
ALTER TABLE `project_technologies`
  ADD CONSTRAINT `fk_ptech_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ptech_tech` FOREIGN KEY (`tech_id`) REFERENCES `technologies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `test_cases`
--
ALTER TABLE `test_cases`
  ADD CONSTRAINT `test_cases_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `test_cases_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `test_plans`
--
ALTER TABLE `test_plans`
  ADD CONSTRAINT `test_plans_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `test_plans_ibfk_2` FOREIGN KEY (`project_lead_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
