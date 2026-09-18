<?php

namespace App\Enums;

/**
 * How consent to hold a staff member's photograph was recorded.
 *
 * This exists because "consent" is not a yes/no — it is a claim the employer may have to
 * defend. A verbal yes witnessed by a manager and a signed form are both legitimate, but
 * they are different strengths of evidence, and a record that cannot say which one it has
 * cannot be relied on.
 *
 * Kept deliberately short. A long list of near-synonyms would invite whoever is entering
 * the data to pick the one that sounds best rather than the one that is true.
 */
enum ConsentMethod: string
{
    /** Agreed in person, with a manager present to confirm it happened. */
    case VERBAL = 'verbal';

    /** Agreed in writing — a message or an email, kept. */
    case WRITTEN = 'written';

    /** A signed form on file. The strongest evidence of the three. */
    case SIGNED_FORM = 'signed_form';

    public function label(): string
    {
        return match ($this) {
            self::VERBAL => 'Verbal, witnessed',
            self::WRITTEN => 'In writing',
            self::SIGNED_FORM => 'Signed form',
        };
    }

    /**
     * How much weight this evidence carries, for ordering and for a cautious warning.
     *
     * Verbal consent is the weakest, and the console says so — not to block it, but so
     * nobody assumes a verbal yes is as easy to defend as a signed form.
     */
    public function weight(): int
    {
        return match ($this) {
            self::VERBAL => 1,
            self::WRITTEN => 2,
            self::SIGNED_FORM => 3,
        };
    }

    public function isStrong(): bool
    {
        return $this->weight() >= self::WRITTEN->weight();
    }

    /** @return array<int, array{value: string, label: string, weight: int}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => [
                'value' => $case->value,
                'label' => $case->label(),
                'weight' => $case->weight(),
            ],
            self::cases(),
        );
    }
}
