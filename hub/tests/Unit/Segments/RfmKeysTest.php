<?php

use App\Domain\Intelligence\Rfm\RfmSegment;
use App\Domain\Segments\Definition\Fields;
use App\Domain\Segments\Library\SegmentLibrary;

// La segmentación y el cálculo RFM deben usar las mismas claves (inteligencia.md §3).
it('usa en segmentos las mismas claves RFM que guarda el cálculo', function () {
    $keys = array_map(fn (RfmSegment $s) => $s->value, RfmSegment::cases());
    expect(Fields::RFM_SEGMENTS)->toBe($keys)
        ->and(array_keys(SegmentLibrary::RFM))->toBe($keys);
});
