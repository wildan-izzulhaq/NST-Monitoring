-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Waktu pembuatan: 02 Agu 2026 pada 16.48
-- Versi server: 8.0.46-cll-lve
-- Versi PHP: 8.4.23

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `diagtemx_nst`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `nst_bookmark`
--

CREATE TABLE `nst_bookmark` (
  `id` int NOT NULL,
  `patient_id` int NOT NULL DEFAULT '0',
  `session_id` int DEFAULT NULL,
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `nst_buffer`
--

CREATE TABLE `nst_buffer` (
  `id` int NOT NULL,
  `patient_id` int NOT NULL DEFAULT '0',
  `sensor` enum('FHR','TOCO') NOT NULL,
  `value` int NOT NULL,
  `bookmark` tinyint(1) NOT NULL DEFAULT '0',
  `ts` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `nst_live`
--

CREATE TABLE `nst_live` (
  `id` int NOT NULL,
  `patient_id` int NOT NULL DEFAULT '0',
  `bpm` smallint UNSIGNED NOT NULL,
  `toco` smallint UNSIGNED NOT NULL,
  `bookmark` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `nst_realtime`
--

CREATE TABLE `nst_realtime` (
  `id` int NOT NULL,
  `patient_id` int NOT NULL DEFAULT '0',
  `bpm` int NOT NULL COMMENT 'Fetal Heart Rate dalam bpm',
  `toco` int NOT NULL COMMENT 'Tekanan kontraksi uterus dalam mmHg',
  `bookmark` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 jika tombol bookmark ditekan',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu pencatatan',
  `session_id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Data real-time dari alat NST';

-- --------------------------------------------------------

--
-- Struktur dari tabel `nst_session`
--

CREATE TABLE `nst_session` (
  `id` int NOT NULL,
  `patient_id` int NOT NULL DEFAULT '0',
  `status` enum('recording','done','cancelled') NOT NULL DEFAULT 'recording',
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` datetime DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `nst_setting`
--

CREATE TABLE `nst_setting` (
  `k` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `v` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `pasien`
--

CREATE TABLE `pasien` (
  `id` int NOT NULL,
  `nama` varchar(100) NOT NULL,
  `tanggal_lahir` date NOT NULL,
  `no_rekam_medis` varchar(50) DEFAULT NULL,
  `usia_kehamilan` int DEFAULT NULL,
  `nama_suami` varchar(100) DEFAULT NULL,
  `no_telp` varchar(20) DEFAULT NULL,
  `catatan` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indeks untuk tabel `nst_bookmark`
--
ALTER TABLE `nst_bookmark`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_patient` (`patient_id`),
  ADD KEY `idx_session` (`session_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indeks untuk tabel `nst_buffer`
--
ALTER TABLE `nst_buffer`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_patient_sensor_ts` (`patient_id`,`sensor`,`ts`);

--
-- Indeks untuk tabel `nst_live`
--
ALTER TABLE `nst_live`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_patient_time` (`patient_id`,`created_at`);

--
-- Indeks untuk tabel `nst_realtime`
--
ALTER TABLE `nst_realtime`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indeks untuk tabel `nst_session`
--
ALTER TABLE `nst_session`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_patient` (`patient_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indeks untuk tabel `nst_setting`
--
ALTER TABLE `nst_setting`
  ADD PRIMARY KEY (`k`);

--
-- Indeks untuk tabel `pasien`
--
ALTER TABLE `pasien`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `nst_bookmark`
--
ALTER TABLE `nst_bookmark`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `nst_buffer`
--
ALTER TABLE `nst_buffer`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `nst_live`
--
ALTER TABLE `nst_live`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `nst_realtime`
--
ALTER TABLE `nst_realtime`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `nst_session`
--
ALTER TABLE `nst_session`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `pasien`
--
ALTER TABLE `pasien`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
