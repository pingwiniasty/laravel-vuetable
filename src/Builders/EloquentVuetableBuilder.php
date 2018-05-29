<?php

namespace Vuetable\Builders;

use Closure;
use Illuminate\Http\Request;

class EloquentVuetableBuilder extends BaseBuilder
{


    /**
     * Query used to make the table data.
     *
     * @var \Illuminate\Database\Eloquent\Builder
     */
    private $query;

    public function __construct(Request $request, $query)
    {
        $this->request = $request;
        $this->query = $query;
    }

    /**
     * Make the vuetable data. The data is sorted, filtered and paginated.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function make()
    {
        $results = $this
            ->sort()
            ->search()
            ->filter()
            ->paginate();

        return $this->applyChangesTo($results);
    }

    /**
     * Paginate the query.
     *
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate()
    {
        $perPage = $this->request->input('per_page');

        return $this->query->paginate($perPage ?: 15);
    }

    /**
     * Add the order by statement to the query.
     *
     * @return $this
     */
    public function sort()
    {
        if (!$this->request->input('sort')) {
            return $this;
        }

        list($field, $direction) = explode('|', $this->request->input('sort'));

        $this->query->orderBy($field, $direction);

        return $this;
    }

    /**
     * Add the where clauses to the query.
     *
     * @return $this
     */
    public function filter()
    {
        if (empty($this->getFilters())) {
            return $this;
        }

        $this->query->where(function ($query) {
            foreach ($this->getFilters() as $column=>$filter) {
                $query->orWhere($column, 'like', "%{$filter}%");
            }
        });

        return $this;
    }

    public function search()
    {
        if($this->request->filled('search') && $this->request->filled('searchable'))
        {
            $searchText = $this->request->get('search');
            $this->query->where(function($query) use ($searchText) {
                foreach($this->request->get('searchable') as $column) {
                    $query->orWhere($column,'like',"%{$searchText}%");
                }
            });
        }

        return $this;
    }


    /**
     * Edit the results inside the pagination object.
     *
     * @param  \Illuminate\Pagination\LengthAwarePaginator $results
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function applyChangesTo($results)
    {
        if (empty($this->columnsToEdit) && empty($this->columnsToAdd)) {
            return $results;
        }

        $newData = $results
            ->getCollection()
            ->map(function ($model) {
                $model = $this->editModelAttibutes($model);
                $model = $this->addModelAttibutes($model);

                return $model;
            });

        return $results->setCollection($newData);
    }

    /**
     * Edit the model attributes acording to the columnsToEdit attribute.
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function editModelAttibutes($model)
    {
        foreach ($this->columnsToEdit as $column => $value) {
            if ($model->hasCast($column)) {
                throw new \Exception("Can not edit the '{$column}' attribute, it has a cast defined in the model.");
            }

            $model = $this->changeAttribute($model, $column, $value);
        }

        return $model;
    }

    /**
     * Add the model attributes acording to the columnsToAdd attribute.
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function addModelAttibutes($model)
    {
        foreach ($this->columnsToAdd as $column => $value) {
            if ($model->relationLoaded($column) || $model->getAttributeValue($column) != null) {
                throw new \Exception("Can not add the '{$column}' column, the results already have that column.");
            }

            $model = $this->changeAttribute($model, $column, $value);
        }

        return $model;
    }

    /**
     * Change a model attribe
     *
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @param  string $attribute
     * @param  string|Closure $value
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function changeAttribute($model, $attribute, $value)
    {
        if ($value instanceof Closure) {
            $model->setAttribute($attribute, $value($model));
        } else {
            $model->setAttribute($attribute, $value);
        }

        if ($model->relationLoaded($attribute)) {
            $model->setRelation($attribute, 'removed');
        }

        return $model;
    }
}
