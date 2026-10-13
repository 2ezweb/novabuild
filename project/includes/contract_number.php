<?php
// Human-facing 6-digit contract number, derived from the internal PK — no
// schema change, stable for the life of a row, and scrambled so it doesn't
// read as a raw sequential id (client shouldn't be able to eyeball how many
// contracts exist on the platform).
//
// Knuth-style multiplicative hash mod 900000, shifted into 100000–999999.
// 2654435761 is coprime to 900000, so id → number is a bijection: no two ids
// ever collide within any range this project will reach. 64-bit PHP int math
// stays exact for ids well past a billion.
function contract_number(int $id): string {
    return str_pad((string) ((($id * 2654435761) % 900000) + 100000), 6, '0', STR_PAD_LEFT);
}
