<?php

namespace Mmedia\LeChat\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Mmedia\LeChat\Models\ChatMessage;
use Staudenmeir\EloquentHasManyDeep\Eloquent\CompositeKey;
use Staudenmeir\EloquentHasManyDeep\HasManyDeep;

/**
 * @internal Resolves visibility columns after deep-relation aliases are assigned.
 *
 * @template TDeclaringModel of Model
 *
 * @extends HasManyDeep<ChatMessage, TDeclaringModel>
 */
final class ParticipantMessages extends HasManyDeep
{
    /**
     * @param  Builder<ChatMessage>  $query
     * @param  TDeclaringModel  $farParent
     * @param  non-empty-list<Model>  $throughParents
     * @param  non-empty-list<array{0: string, 1: string}|callable|string|CompositeKey|null>  $foreignKeys
     * @param  non-empty-list<array{0: string, 1: string}|callable|string|CompositeKey|null>  $localKeys
     */
    public function __construct(Builder $query, Model $farParent, array $throughParents, array $foreignKeys, array $localKeys)
    {
        parent::__construct($query, $farParent, $throughParents, $foreignKeys, $localKeys);
    }

    public function addConstraints(): void
    {
        parent::addConstraints();

        $this->addVisibilityScope($this->query);
    }

    /** {@inheritDoc} */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']): Builder
    {
        $query = parent::getRelationExistenceQuery($query, $parentQuery, $columns);
        $this->addVisibilityScope($query);

        return $query;
    }

    /** @param Builder<ChatMessage> $query */
    private function addVisibilityScope(Builder $query): void
    {
        $query->withGlobalScope('le-chat.membership-visibility', function (Builder $query): void {
            $membership = $this->getThroughParents()[0];
            if (! method_exists($membership, 'getQualifiedDeletedAtColumn')) {
                throw new LogicException('Membership message visibility requires a soft-deletable through model.');
            }
            $membershipDeletedAt = $membership->getQualifiedDeletedAtColumn();
            $messageCreatedAt = $query->getModel()->getQualifiedCreatedAtColumn();

            $query->where(function (Builder $query) use ($membership, $messageCreatedAt): void {
                $query->where($membership->qualifyColumn('can_see_messages_before_joined'), true)
                    ->orWhereColumn($messageCreatedAt, '>=', $membership->getQualifiedCreatedAtColumn());
            })->where(function (Builder $query) use ($membershipDeletedAt, $messageCreatedAt): void {
                $query->whereNull($membershipDeletedAt)
                    ->orWhereColumn($messageCreatedAt, '<=', $membershipDeletedAt);
            });
        });
    }
}
