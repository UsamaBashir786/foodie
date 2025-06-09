-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Jun 02, 2025 at 06:22 PM
-- Server version: 9.1.0
-- PHP Version: 8.3.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `foodiehub`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

DROP TABLE IF EXISTS `admin`;
CREATE TABLE IF NOT EXISTS `admin` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `security_question` varchar(255) NOT NULL,
  `security_answer` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`id`, `username`, `password`, `security_question`, `security_answer`, `created_at`) VALUES
(3, 'foodiehub@admin.com', '$2y$10$9GXO5K6gsjTTHNmI8X6viOxw2N7phSbtgymphVRiz8odbUozrLSWi', 'What is the name of your first pet?', '$2y$10$kheYGsO1aQdeNDFwrFoG2.OXfXKaPvIuc8kVklvw9WEUyS3l8McKG', '2025-06-01 13:00:10');

-- --------------------------------------------------------

--
-- Table structure for table `contact_messages`
--

DROP TABLE IF EXISTS `contact_messages`;
CREATE TABLE IF NOT EXISTS `contact_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `subject` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `menu_items`
--

DROP TABLE IF EXISTS `menu_items`;
CREATE TABLE IF NOT EXISTS `menu_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vendor_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `category` enum('Pizzas','Sides','Drinks','Desserts') NOT NULL,
  `description` text,
  `price` decimal(10,2) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `menu_items`
--

INSERT INTO `menu_items` (`id`, `vendor_id`, `name`, `category`, `description`, `price`, `image`, `created_at`) VALUES
(8, 2, 'Pizza', 'Pizzas', 'gfdsg', 22.00, 'Uploads/menu_683cc6241f7505.01560698.png', '2025-06-01 21:29:08');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
CREATE TABLE IF NOT EXISTS `orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_type` enum('Order','Booking') NOT NULL DEFAULT 'Order',
  `user_id` int NOT NULL,
  `vendor_id` int NOT NULL,
  `total` decimal(10,2) NOT NULL,
  `delivery_address` varchar(255) DEFAULT '',
  `payment_method` varchar(50) DEFAULT '',
  `order_date` datetime NOT NULL,
  `status` enum('Pending','Processing','Delivered','Cancelled') DEFAULT 'Pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `delivery_fee` decimal(10,2) DEFAULT '0.00',
  `discount_amount` decimal(10,2) DEFAULT '0.00',
  `subtotal` decimal(10,2) DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `vendor_id` (`vendor_id`)
) ENGINE=MyISAM AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `order_type`, `user_id`, `vendor_id`, `total`, `delivery_address`, `payment_method`, `order_date`, `status`, `created_at`, `updated_at`, `delivery_fee`, `discount_amount`, `subtotal`) VALUES
(7, 'Order', 2, 2, 272.00, 'Wahga Border', '0', '2025-06-02 18:19:49', 'Pending', '2025-06-02 18:19:49', '2025-06-02 18:19:49', 250.00, 0.00, 22.00);

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

DROP TABLE IF EXISTS `order_items`;
CREATE TABLE IF NOT EXISTS `order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `menu_item_id` int NOT NULL,
  `quantity` int NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `booking_details` text,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `menu_item_id` (`menu_item_id`)
) ENGINE=MyISAM AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `menu_item_id`, `quantity`, `price`, `subtotal`, `created_at`, `booking_details`) VALUES
(7, 7, 8, 1, 22.00, 22.00, '2025-06-02 18:19:49', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

DROP TABLE IF EXISTS `reservations`;
CREATE TABLE IF NOT EXISTS `reservations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `vendor_id` int NOT NULL,
  `subscription_id` int DEFAULT NULL,
  `slot_id` int NOT NULL,
  `reservation_date` datetime NOT NULL,
  `party_size` int NOT NULL,
  `status` enum('confirmed','cancelled') DEFAULT 'confirmed',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `vendor_id` (`vendor_id`),
  KEY `slot_id` (`slot_id`),
  KEY `subscription_id` (`subscription_id`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reservation_slots`
--

DROP TABLE IF EXISTS `reservation_slots`;
CREATE TABLE IF NOT EXISTS `reservation_slots` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vendor_id` int NOT NULL,
  `slot_date` date NOT NULL,
  `slot_time` time NOT NULL,
  `capacity` int NOT NULL,
  `status` enum('available','fully_booked') DEFAULT 'available',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

DROP TABLE IF EXISTS `subscriptions`;
CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vendor_id` int NOT NULL,
  `plan_type` enum('Basic','Standard','Premium') NOT NULL,
  `dish_limit` int NOT NULL,
  `meal_times` varchar(255) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `validity_period` int NOT NULL,
  `validity_unit` enum('Days','Weeks','Months') NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `discount_percentage` decimal(5,2) NOT NULL DEFAULT '0.00',
  `non_subscriber_delivery_fee` decimal(10,2) NOT NULL DEFAULT '250.00',
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `subscriptions`
--

INSERT INTO `subscriptions` (`id`, `vendor_id`, `plan_type`, `dish_limit`, `meal_times`, `price`, `validity_period`, `validity_unit`, `status`, `created_at`, `updated_at`, `discount_percentage`, `non_subscriber_delivery_fee`) VALUES
(3, 2, 'Basic', 3, 'Morning', 1500.00, 2, 'Days', 'active', '2025-06-01 23:17:20', NULL, 10.00, 250.00),
(4, 2, 'Standard', 6, 'Morning,Afternoon', 2500.00, 3, 'Days', 'active', '2025-06-01 23:17:44', NULL, 15.00, 250.00),
(5, 2, 'Premium', 10, 'Morning,Afternoon,Evening', 5000.00, 10, 'Days', 'active', '2025-06-01 23:18:07', NULL, 20.00, 250.00);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `first_name`, `last_name`, `email`, `phone`, `password`, `remember_token`, `address`, `city`, `postal_code`, `created_at`) VALUES
(2, 'test', 'test', 'test@test.com', '03196977218', '$2y$10$f5XfeOZoC2OMcy9DTCqTzuYn6dLKx4VktnzVTnhapb.kAHp05MRbK', NULL, 'Pakistan Punjab, Sahiwal', 'Sahiwal', '57000', '2025-05-31 15:33:19');

-- --------------------------------------------------------

--
-- Table structure for table `user_subscriptions`
--

DROP TABLE IF EXISTS `user_subscriptions`;
CREATE TABLE IF NOT EXISTS `user_subscriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `subscription_id` int NOT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `status` enum('pending','active','cancelled') DEFAULT 'pending',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `subscription_id` (`subscription_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vendors`
--

DROP TABLE IF EXISTS `vendors`;
CREATE TABLE IF NOT EXISTS `vendors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `restaurant_name` varchar(255) NOT NULL,
  `email` varchar(191) NOT NULL,
  `password` varchar(255) NOT NULL,
  `category` varchar(100) NOT NULL,
  `contact_number` varchar(15) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('pending','active','rejected') NOT NULL DEFAULT 'pending',
  `license` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `vendors`
--

INSERT INTO `vendors` (`id`, `restaurant_name`, `email`, `password`, `category`, `contact_number`, `created_at`, `status`, `license`) VALUES
(2, 'vendor', 'vendor@vendor.com', '$2y$10$dfTPZrDclhrhTzULUsLc3..aZ4GhPOhisHWfE4oAFCaaTJ3grgyZq', 'Pizza', '03196977218', '2025-05-31 15:47:02', 'rejected', ''),
(3, 'vendor', 'test@test.com', '$2y$10$GBIklD/fbg1zetBHmBtvm.k7FUBiQ6nvglFWnXz8Z54yYwM8lqbfW', 'Italian', '03196977218', '2025-06-02 17:49:19', 'active', '12345678');

-- --------------------------------------------------------

--
-- Table structure for table `vendor_images`
--

DROP TABLE IF EXISTS `vendor_images`;
CREATE TABLE IF NOT EXISTS `vendor_images` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vendor_id` int NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `vendor_id` (`vendor_id`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `vendor_images`
--

INSERT INTO `vendor_images` (`id`, `vendor_id`, `image_path`, `uploaded_at`) VALUES
(1, 2, 'Uploads/vendor_2_683b24761c47d.jpeg', '2025-05-31 15:47:02'),
(2, 2, 'Uploads/vendor_2_683b24761ce71.jpg', '2025-05-31 15:47:02'),
(3, 2, 'Uploads/vendor_2_683b24761d525.jpg', '2025-05-31 15:47:02'),
(4, 2, 'Uploads/vendor_2_683b24761ddc9.jpg', '2025-05-31 15:47:02'),
(5, 3, 'Uploads/vendor_3_683de41fcfe4b.jpg', '2025-06-02 17:49:19');

-- --------------------------------------------------------

--
-- Table structure for table `vendor_schedules`
--

DROP TABLE IF EXISTS `vendor_schedules`;
CREATE TABLE IF NOT EXISTS `vendor_schedules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `vendor_id` int NOT NULL,
  `schedule_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_schedule` (`vendor_id`,`schedule_date`,`start_time`,`end_time`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `vendor_schedules`
--

INSERT INTO `vendor_schedules` (`id`, `vendor_id`, `schedule_date`, `start_time`, `end_time`, `created_at`, `updated_at`) VALUES
(3, 1, '2025-05-31', '10:00:00', '22:00:00', '2025-05-31 15:28:26', '2025-05-31 15:28:26'),
(4, 2, '2025-05-31', '10:00:00', '22:00:00', '2025-05-31 15:48:57', '2025-05-31 15:48:57'),
(5, 2, '2025-06-01', '10:00:00', '22:00:00', '2025-06-01 14:05:39', '2025-06-01 14:05:39'),
(6, 2, '2025-06-02', '14:00:00', '22:00:00', '2025-06-01 21:26:38', '2025-06-01 21:26:38');

--
-- Constraints for dumped tables
--

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_subscriptions`
--
ALTER TABLE `user_subscriptions`
  ADD CONSTRAINT `user_subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_subscriptions_ibfk_2` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
