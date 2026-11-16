<?php

declare(strict_types=1);

// Per field in our database: all column names members use for it.
// Write aliases in lower case; matching ignores case.
return [
    'member'   => ['naam', 'imker', 'imker_naam', 'membername', 'lid', 'wie'],
    'beehouse' => ['bijenstand', 'locatie', 'apiary', 'stand', 'standplaats'],
    'colony'   => ['volkid', 'volknaam', 'id_volk', 'hiveidentifier', 'volk', 'volk_id'],
    'date'     => ['datum', 'oogstdatum', 'datum_oogst', 'harvestdate', 'wanneer'],
    
    // gewicht_kg = column from our own export, so the export can be imported again
    'weight'   => ['oogst_kg', 'gewicht', 'opbrengst', 'yieldamount', 'kilo', 'gewicht_kg'],
    
    'unit'     => ['eenheid', 'unit'],
];
