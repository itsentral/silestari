<?php

namespace App\Support;

class ModuleRegistry
{
    /** @return array<string, string> */
    public static function all(): array
    {
        return [
            'company' => 'Profil Perusahaan', 'policy' => 'Kebijakan Perusahaan', 'issues' => 'Isu Internal & Eksternal', 'parties' => 'Pihak Berkepentingan', 'risk' => 'HIRADC & IADL', 'actions' => 'Rencana Tindakan', 'targets' => 'Sasaran & Target (BSC)', 'legal' => 'Kepatuhan Peraturan', 'moc' => 'Management of Change', 'chemicals' => 'Pengendalian B3', 'waste' => 'Pengendalian Limbah B3', 'solidWaste' => 'Pengendalian Sampah', 'emergency' => 'Kesiapsiagaan Tanggap Darurat', 'communication' => 'Komunikasi & Partisipasi', 'documents' => 'Document Control', 'training' => 'Pelatihan dan Pengembangan SDM', 'csms' => 'CSMS', 'workPermits' => 'Izin Kerja & JSA', 'monitor' => 'Pemantauan K3L', 'analysis' => 'Analisa Lingkungan & K3', 'audit' => 'Audit Internal', 'managementReview' => 'Tinjauan Manajemen', 'nonconformity' => 'Ketidaksesuaian & Tindakan Perbaikan', 'incidents' => 'Insiden', 'kaizen' => 'Kaizen'];
    }
}
