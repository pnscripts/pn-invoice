<?php

declare(strict_types=1);

namespace Pnscripts\Invoice\Model;

/**
 * VAT category codes allowed by EN 16931 (subset of UNTDID 5305).
 *
 * The library never hard-codes VAT rates or countries: the rate travels with
 * each {@see TaxCategory} as data supplied by the caller.
 */
enum VatCategory: string
{
    /** Standard rate (any rate greater than zero). */
    case StandardRate = 'S';
    /** Zero rated goods. */
    case ZeroRated = 'Z';
    /** Exempt from VAT. */
    case Exempt = 'E';
    /** VAT reverse charge. */
    case ReverseCharge = 'AE';
    /** VAT exempt for EEA intra-community supply of goods and services. */
    case IntraCommunity = 'K';
    /** Free export item, VAT not charged. */
    case Export = 'G';
    /** Services outside scope of tax. */
    case NotSubjectToVat = 'O';
    /** Canary Islands general indirect tax (IGIC). */
    case CanaryIslands = 'L';
    /** Tax for production, services and importation in Ceuta and Melilla (IPSI). */
    case CeutaMelilla = 'M';

    /**
     * Whether a VAT amount is calculated from the rate (S, L, M). For all other
     * categories the VAT amount is always zero.
     */
    public function isTaxed(): bool
    {
        return match ($this) {
            self::StandardRate, self::CanaryIslands, self::CeutaMelilla => true,
            default => false,
        };
    }

    /**
     * Whether EN 16931 expects a VAT exemption reason (BT-120 / BT-121) for this category.
     */
    public function requiresExemptionReason(): bool
    {
        return match ($this) {
            self::Exempt, self::ReverseCharge, self::IntraCommunity, self::Export, self::NotSubjectToVat => true,
            default => false,
        };
    }

    /**
     * Category "O" carries no VAT rate at all (BR-O-05 and related rules).
     */
    public function hasRate(): bool
    {
        return $this !== self::NotSubjectToVat;
    }
}
