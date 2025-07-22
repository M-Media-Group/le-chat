<?php

namespace Mmedia\LeChat\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 *
 * @template T of \Illuminate\Database\Eloquent\SoftDeletes
 *
 * @mixin T
 *
 * @template M of \Illuminate\Database\Eloquent\Model
 *
 * @mixin M
 */
trait OverwriteDeletes
{
    /**
     * Perform the actual delete query on this model instance.
     *
     * @return void
     */
    protected function runSoftDelete()
    {
        $query = $this->setKeysForSaveQuery($this->newModelQuery());

        $time = $this->freshTimestamp();

        $columns = [$this->getDeletedAtColumn() => $this->fromDateTime($time)];

        $this->{$this->getDeletedAtColumn()} = $time;

        if ($this->usesTimestamps() && ! is_null($this->getUpdatedAtColumn())) {
            $this->{$this->getUpdatedAtColumn()} = $time;

            $columns[$this->getUpdatedAtColumn()] = $this->fromDateTime($time);
        }

        // Apply the deletable attributes
        foreach ($this->deletable as $attribute => $value) {
            $columns[$attribute] = $value;
        }

        $query->update($columns);

        $this->syncOriginalAttributes(array_keys($columns));

        $this->fireModelEvent('trashed', false);
    }

    /**
     * Restore a soft-deleted model instance.
     *
     * @return bool
     */
    public function restore()
    {
        throw new \BadMethodCallException('Restoring soft-deleted models with overwrites is not supported, because the data cannot be recovered.');
    }

    public function scopeWithTrashed(Builder $query, bool $withTrashed = true): Builder
    {
        if (! $withTrashed) {
            return $query->withoutTrashed();
        }

        // If we are including trashed, we just return the query as is
        return $query;
    }

    public function scopeWithoutTrashed(Builder $query): Builder
    {
        return $query->whereNull($this->getQualifiedDeletedAtColumn());
    }

    public function scopeOnlyTrashed(Builder $query): Builder
    {
        return $query->whereNotNull($this->getQualifiedDeletedAtColumn());
    }
}
