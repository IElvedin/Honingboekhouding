<?php

declare(strict_types=1);

// Per official beehouse: all spellings members use for it.
//
// Case, spaces, dots and hyphens do not matter: the resolver only compares
// letters and digits. "Zonne weide", "zonneweide" and "ZONNEWEIDE" therefore
// already match "Zonneweide" and do not need to be listed here.
//
// DELIBERATELY NOT INCLUDED (awaiting confirmation from the treasurer, see README):
//   Klaasveld
//   It ends up in import_log as "onbekende bijenstand" (unknown beehouse).
//   Probable mapping: Klaasveld -> Klaproos.
return [
    'Achter de Kerk' => ['A. de Kerk', 'achter d kerk', 'AdK', 'Kerk', 'Kerkhof'],
    'Bosrand'        => ['De Bosrand', 'Bosrand Noord', 'Bosrand Zuid'],
    'Heideveld'      => ['De Heide', 'HV', 'Heideveld Zuid'],
    'Klaproos'       => ['Klaproosplein', 'Klaproosweg'],
    'Molenstraat'    => ['Molen', 'Molenpad', 'Molenweide'],
    'Zonneweide'     => ['ZW', 'ZW-1', 'Zonneweide West'],
];
