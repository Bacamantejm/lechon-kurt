<?php

namespace App\Services;

class GovernmentIdVerificationService
{
    /**
     * Recognized Philippine Government ID Types
     */
    protected const SUPPORTED_IDS = [
        'philsys' => 'Philippine National ID (PhilSys)',
        'drivers_license' => "LTO Driver's License",
        'passport' => 'DFA Philippine Passport',
        'sss' => 'Social Security System (SSS / UMID)',
        'tin' => 'Bureau of Internal Revenue (BIR TIN)',
        'philhealth' => 'PhilHealth Insurance ID',
        'pagibig' => 'Pag-IBIG Loyalty Card',
        'postal' => 'PhilPost Postal ID',
        'prc' => 'Professional Regulation Commission (PRC)',
        'voter' => 'COMELEC Voter ID / Certification',
        'senior' => 'OSCA Senior Citizen ID',
    ];

    /**
     * Check if ID type is supported
     */
    public function isValidIdType(string $type): bool
    {
        return array_key_exists(strtolower(trim($type)), self::SUPPORTED_IDS);
    }

    /**
     * Validate Philippine ID numbering format patterns
     */
    public function validateIdNumberFormat(string $idType, string $idNumber): bool
    {
        $cleaned = preg_replace('/[^0-9A-Za-z]/', '', strtoupper(trim($idNumber)));
        
        return match (strtolower(trim($idType))) {
            'philsys' => (bool)preg_match('/^\d{16}$|^\d{4}\d{4}\d{4}\d{4}$/', $cleaned),
            'drivers_license' => (bool)preg_match('/^[A-Z]\d{2}\d{2}\d{6}$|^\d{11}$/', $cleaned),
            'passport' => (bool)preg_match('/^[A-Z]\d{7}[A-Z]$|^[A-Z]\d{8}$/', $cleaned),
            'sss' => (bool)preg_match('/^\d{10}$|^\d{12}$/', $cleaned),
            'tin' => (bool)preg_match('/^\d{9}$|^\d{12}$/', $cleaned),
            'philhealth' => (bool)preg_match('/^\d{12}$/', $cleaned),
            'pagibig' => (bool)preg_match('/^\d{12}$/', $cleaned),
            'postal' => (bool)preg_match('/^[A-Z0-9]{10,16}$/', $cleaned),
            default => strlen($cleaned) >= 6,
        };
    }

    /**
     * Tokenized fuzzy name similarity engine
     */
    public function calculateNameMatchScore(string $registeredName, string $idExtractedName): float
    {
        $regTokens = array_filter(explode(' ', strtolower(preg_replace('/[^a-z ]/', '', $registeredName))));
        $idTokens = array_filter(explode(' ', strtolower(preg_replace('/[^a-z ]/', '', $idExtractedName))));

        if (empty($regTokens) || empty($idTokens)) {
            return 0.0;
        }

        $matchedCount = 0;
        foreach ($regTokens as $token) {
            foreach ($idTokens as $idToken) {
                if ($token === $idToken || similar_text($token, $idToken, $percent) && $percent >= 80) {
                    $matchedCount++;
                    break;
                }
            }
        }

        return round(($matchedCount / count($regTokens)) * 100, 2);
    }
}
