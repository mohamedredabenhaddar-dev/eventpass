<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TicketPdfService
{
    /**
     * Generate a standards-compliant PDF for a ticket (pure PHP, no external deps).
     */
    public function generate(Model $event, Model $ticket): string
    {
        $status = $ticket->status?->value ?? (string) $ticket->status;
        $accessState = $ticket->access_state?->value ?? (string) ($ticket->access_state ?? 'NOT_ENTERED');

        $lines = [
            'EventPass Ticket',
            'Event: '.$event->title,
            'Location: '.trim(($event->venue_name ?? '').', '.($event->city ?? ''), ', '),
            'Date: '.Carbon::parse($event->start_at)->format('M d, Y @ H:i'),
            'Ticket Type: General Admission',
            'Ticket Code: '.$ticket->reference,
            'Entry Code: VERIFY-QR-'.$ticket->reference,
            'Entry Status: '.$status,
            'Access State: '.$accessState,
        ];

        $stream = "BT\n/F1 16 Tf\n40 750 Td\n";
        $firstLine = true;
        foreach ($lines as $line) {
            if (! $firstLine) {
                $stream .= "/F1 11 Tf\n0 -22 Td\n";
            }
            $stream .= '('.$this->escape($line).") Tj\n";
            $firstLine = false;
        }
        $stream .= 'ET';

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            5 => '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
        ];

        $document = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $number => $object) {
            $offsets[$number] = strlen($document);
            $document .= $number." 0 obj\n".$object."\nendobj\n";
        }

        $xrefOffset = strlen($document);
        $size = count($objects) + 1;
        $document .= "xref\n0 ".$size."\n0000000000 65535 f \n";

        for ($number = 1; $number <= count($objects); $number++) {
            $document .= sprintf("%010d 00000 n \n", $offsets[$number]);
        }

        $document .= 'trailer << /Size '.$size.' /Root 1 0 R >>'."\n";
        $document .= "startxref\n".$xrefOffset."\n%%EOF\n";

        return $document;
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }
}
