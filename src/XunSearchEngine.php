<?php
/**
 * Created by PhpStorm.
 * User: Gary.F.Dong
 * Date: 2017/2/6
 * Time: 11:11
 * Desc:
 */

namespace Jiyis\XunSearch;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine as Engine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class XunSearchEngine extends Engine
{
    private $xs;

    private function setDB($model)
    {
        $columns = $this->changeType($model->getColumns());
        $config  = Cache::remember('xunsearch_' . $model->searchableAs(), 60 * 24 * 7,
            function () use ($model, $columns) {
                return array_merge([
                    'project.name'            => $model->searchableAs(),
                    'project.default_charset' => config('xunsearch.charset'),
                    'project.index'           => config('xunsearch.host') . ':' . config('xunsearch.indexport'),
                    'project.search'          => config('xunsearch.host') . ':' . config('xunsearch.searchport'),
                    'id'                      => ['type' => 'id'],
                ], $columns);
            });

        $this->xs = new \XS($config);
    }

    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection $models
     * @throws \XSException
     * @return void
     */
    public function update($models)
    {
        $this->setDB($models->first());
        $index = $this->xs->index;
        $models->map(function ($model) use ($index) {
            $array = $model->toSearchableArray();
            if (empty($array)) {
                return;
            }
            $array['id'] = $model->getKey();
            $doc         = new \XSDocument;
            $doc->setFields($array);
            $index->update($doc);
        });
        $index->flushIndex();
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection $models
     * @return void
     */
    public function delete($models)
    {
        $this->setDB($models->first());
        $index = $this->xs->index;
        $models->map(function ($model) use ($index) {
            $index->del($model->getKey());
        });
        $index->flushIndex();
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'hitsPerPage'    => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder $builder
     * @param  int $perPage
     * @param  int $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, [
            'numericFilters' => $this->filters($builder),
            'hitsPerPage'    => $perPage,
            'page'           => $page - 1,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder $builder
     * @param  array $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $this->setDB($builder->model);
        $xunsearch = $this->xs->search;

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $xunsearch,
                $builder->query,
                $options
            );
        }
        $xunsearch->setFuzzy()->setQuery($builder->query);
        /*collect($builder->wheres)->map(function ($value, $key) use ($xunsearch) {
            $xunsearch->addRange($key, $value, $value);
        });*/

        $offset  = 0;
        $perPage = $options['hitsPerPage'];
        if (!empty($options['page'])) {
            $offset = $perPage * $options['page'];
        }
        return $xunsearch->setLimit($perPage, $offset)->search();
    }

    /**
     * Get the filter array for the query.
     *
     * @param  \Laravel\Scout\Builder $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        return collect($builder->wheres)->map(function ($value, $key) {
            return $key . '=' . $value;
        })->values()->all();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed $results
     * @param  \Illuminate\Database\Eloquent\Model $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map($results, $model)
    {
        if (count($results) === 0) {
            return Collection::make();
        }
        $keys = collect($results)
            ->pluck('id')->values()->all();

        $models = $model->whereIn(
            $model->getQualifiedKeyName(), $keys
        )->get()->keyBy($model->getKeyName());

        return Collection::make($results)->map(function ($hit) use ($model, $models) {
            $key = $hit['id'];
            if (isset($models[$key])) {
                $models[$key]->document = [
                    'docid'   => $hit->docid(),
                    'percent' => $hit->percent(),
                    'rank'    => $hit->rank(),
                    'weight'  => $hit->weight(),
                    'ccount'  => $hit->ccount(),
                ];
                return $models[$key];
            }
        })->filter();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed $results
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits'])->pluck('id')->values();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results->getLastCount();
    }

    /**
     * 根据xunsearch的配置项规范，动态生成
     * @param $attrs
     * @return mixed
     */
    public function changeType($attrs)
    {
        $titleMark = false;
        foreach ($attrs as $value => $type) {
            if (in_array($value, ['updated_at', 'deleted_at'])) continue;
            switch ($type) {
                case 'integer' :
                case 'double' :
                    $array = ['type' => 'numeric'];
                    break;
                case 'string' :
                    $array = ['type' => 'string', 'index' => 'both'];
                    if (!$titleMark) {
                        $array     = ['type' => 'title'];
                        $titleMark = true;
                    }
                    break;
                case 'text' :
                    $array = ['type' => 'body', 'cutlen' => '500'];
                    break;
                case 'datetime' :
                    $array = ['type' => 'string'];
                    break;
            }
            $config[$value] = $array;
        }
        return $config;
    }

    /**
     * 获取xunsearch的实例
     * @return mixed
     */
    public function getXunSearch()
    {
        return $this->xs;
    }
}