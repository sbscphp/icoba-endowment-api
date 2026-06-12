<?php

namespace App\Enums;

enum PublicDocumentType: string
{
    case RecognitionCertificate = 'recognition_certificate';
    case DonationReceipt = 'donation_receipt';
    case TaxReceipt = 'tax_receipt';
}
