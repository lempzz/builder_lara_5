<?php
namespace Vis\Builder\Fields;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class ForeignField extends AbstractField
{
    private $treeMy;
    private $treeOptions;
    private $recursiveOnlyLastLevel = false;
    private $selectOption;

    public function isEditable()
    {
        return true;
    }

    public function getExportValue($type, $row, $postfix = '')
    {
        $value = $this->getValue($row, $postfix);

        if ($value == '<i class="fa fa-minus"></i>') {
            $value = '';
        }
        // cuz double quotes is escaping by more double quotes in csv
        $escapedValue = preg_replace('~"~', '""', $value);
        return $escapedValue;
    }

    public function getFilterInput()
    {
        if (!$this->getAttribute('filter')) return;

        $definitionName = $this->getOption('def_name');
        $sessionPath = 'table_builder.' . $definitionName . '.filters.' . $this->getFieldName ();
        $filter = Session::get($sessionPath, '');
        $type = $this->getAttribute('filter');
        $input = view('admin::tb.filter_' . $type);
        $input->name = $this->getFieldName();
        $input->selected = $filter;
        $input->recursive = $this->getAttribute('recursive');
        $input->value = $filter;
        $input->definitionName = $definitionName;

        if ($type == 'foreign') {
            if ($input->recursive) {
                $this->treeMy = $this->getCategory($this->getAttribute('recursiveIdCatalog'));
                $this->recursiveOnlyLastLevel = $this->getAttribute('recursiveOnlyLastLevel');
                $this->selectOption = $input->selected;
                $this->printCategories($this->getAttribute('recursiveIdCatalog'), 0);
                $input->options = $this->treeOptions;
            } else {
                $input->options  = $this->getForeignKeyOptions();
            }
        }

        if ($type == 'autocomplete') {
            $input->valueJson = $this->getSelectFilterTitle($filter);
        }

        return $input->render();
    }

    private function getSelectFilterTitle($id)
    {
       return DB::table ($this->getAttribute('foreign_table'))->select($this->getForeignValueFields())
            ->find($id);
    }

    public function onSearchFilter(&$db, $value)
    {
        $foreignTable = $this->getAttribute('foreign_table');
        if ($this->getAttribute('alias')) {
            $foreignTable = $this->getAttribute('alias');
        }
        $foreignValueField = $foreignTable .'.'. $this->getAttribute('foreign_value_field');

        if ($this->getAttribute('filter') == 'text') {
            $db->where($foreignValueField, 'LIKE', '%'.$value.'%');
            return;
        }

        $foreignValueField = $foreignTable .'.'. $this->getAttribute('foreign_key_field');
        $db->where($foreignValueField, $value);
    }

    public function onSelectValue(&$db)
    {
        if ($this->hasCustomHandlerMethod('onAddSelectField')) {
            $res = $this->handler->onAddSelectField($this, $db);
            if ($res) return $res;
        }

        $internalSelect = $this->definition['db']['table'] .'.'. $this->getFieldName();
        $db->addSelect($internalSelect);
        $foreignTable = $this->getAttribute('foreign_table');
        $foreignTableName = $foreignTable;

        if ($this->getAttribute('alias')) {
            $foreignTableName .= ' as '. $this->getAttribute('alias');
            $foreignTable = $this->getAttribute('alias');
        }

        $foreignKeyField = $foreignTable .'.'. $this->getAttribute('foreign_key_field');
        $join = $this->getAttribute('is_null') ? 'leftJoin' : 'join';
        $db->$join(
            $foreignTableName,
            $foreignKeyField, '=', $internalSelect
        );

        $foreignValueFields = $this->getForeignValueFields();

        foreach ($foreignValueFields as $field) {
            $fieldAlias = ' as '. $foreignTable.'_'.$field;
            $db->addSelect($foreignTable .'.'. $field . $fieldAlias);
        }
    }

    public function getValueId($row)
    {
        $fieldName = $this->getFieldName();
        $value = isset($row[$fieldName]) ? $row[$fieldName] : '';
        return $value;
    }

    public function getNewValueId($input='')
    {
        $new_id = null;
        if (strlen($input)) {
            $foreignTable = $this->getAttribute('foreign_table');
            $foreignValueField = $this->getAttribute('foreign_value_field');

            $first = DB::table($foreignTable)->where($foreignValueField, '=', $input)->first();
            if (!$first) {
                $new_id = DB::table($foreignTable)->insertGetId([$foreignValueField => $input]);
            }
        }
        return $new_id;
    }

    public function getValue($row, $postfix = '')
    {
        if ($this->hasCustomHandlerMethod('onGetValue')) {
            $res = $this->handler->onGetValue($this, $row, $postfix);
            if ($res)  return $res;
        }

        $foreignValueFields = $this->getForeignValueFields();

        $foreignTableName = $this->getAttribute('alias')
                                        ? $this->getAttribute('alias')
                                        : $this->getAttribute('foreign_table');

        $resultArray = array_map(function($v) use ($foreignTableName) {
            return $foreignTableName .'_'.$v;
        }, $foreignValueFields);

        $needsField = array_only($row, $resultArray);

        $value = trim(implode (" ", $needsField));
        if (!$value && $this->getAttribute('is_null')) {
            $value = $this->getAttribute('null_caption', '<i class="fa fa-minus"></i>');
        }

        return $value;
    }

    public function getEditInput($row = array())
    {
        if ($this->hasCustomHandlerMethod('onGetEditInput')) {
            $res = $this->handler->onGetEditInput($this, $row);
            if ($res) return $res;
        }

        if ($this->getAttribute('is_readonly')) return $this->getValue($row);

        $input = $this->getAttribute('ajax_search') ?
            view('admin::tb.input_foreign_ajax_search') :
            view('admin::tb.input_foreign');

        $input->selected = $this->getSelected($row);

        // if show definition
        if (request('foreign_field_id')) {
            $input = view('admin::tb.input_foreign_only_read');
            $input->selected = $this->getSelectedOnlyRead(request('foreign_field_id'));
        }

        $input->name = $this->getFieldName();
        $input->search = $this->getAttribute('search');
        $input->is_null = $this->getAttribute('is_null');
        $input->null_caption = $this->getAttribute('null_caption');
        $input->recursive = $this->getAttribute('recursive');
        $input->allow_foreign_add = $this->getAttribute('foreign_allow_add');

        if ($input->recursive) {
            $this->treeMy = $this->getCategory($this->getAttribute('recursiveIdCatalog'));
            $this->recursiveOnlyLastLevel = $this->getAttribute('recursiveOnlyLastLevel');
            $this->selectOption = $input->selected;
            $this->printCategories($this->getAttribute('recursiveIdCatalog'), 0);
            $input->options = $this->treeOptions;
        } else {
            $input->options  = $this->getForeignKeyOptions();
        }

        $input->readonly_for_edit = $this->getAttribute('readonly_for_edit');
        $input->relation = $this->getAttribute('relation');
        $input->field = $this->attributes;

        return $input->render();
    }

    private function getSelectedOnlyRead($id)
    {
        $result = $this->getSelectedById($id);

        return implode (" ", $result);
    }

    private function getSelected($row)
    {
        if ($this->getAttribute('ajax_search')) {

            $id = $this->getValueId($row);

            if ($id) {

                $result = $this->getSelectedById($id);

                return [
                    'id' => $id,
                    'name' => implode (" ", $result)
                ];
            }
        }

        return $this->getValueId($row);;
    }

    private function getSelectedById($id) {

        $foreignValueFields = $this->getForeignValueFields();

        $result = DB::table($this->getAttribute('foreign_table'))
            ->select($foreignValueFields)
            ->where($this->getAttribute('foreign_key_field'), $id)->first();

        $result = $this->replaceObjectToArray($result);

        return $result;
    }

    private function getCategory($id)
    {
        $model = $this->getModelRecursive();

        $node = $model::find($id);
        $children = $node->descendants();
        $additionalWhere = $this->getAttribute('additional_where');

        if ($additionalWhere) {
            foreach ($additionalWhere as $field => $where) {
                if ($where['sign'] == 'in') {
                    $children = $children->whereIn($field, $where['value']);
                } else {
                    $children = $children->where($field, $where['sign'], $where['value']);
                }
            }
        }
        
        $children = $children->get(array("id", "title", "parent_id"))->toArray();

        $result = array();
        foreach ($children as $row) {
            $result[$row["parent_id"]][] = $row;
        }

        return $result;
    }

    private function getModelRecursive()
    {
        return $this->getAttribute('recursiveUseModel') ? : 'Tree';
    }

    private function printCategories($parent_id, $level)
    {
        //Делаем переменную $category_arr видимой в функции
        if (isset($this->treeMy[$parent_id])) { //Если категория с таким parent_id существует
            foreach ($this->treeMy[$parent_id] as $value) { //Обходим
                if (isset($this->treeMy[$value["id"]]) && $this->recursiveOnlyLastLevel) {
                    $disable = "disabled";
                } else {
                    $disable = "";
                }
                $selectOption = $this->selectOption == $value["id"] ? "selected" : "";
                $paddingLeft = "";
                for ($i=0; $i<$level; $i++) {
                    $paddingLeft .= "--";
                }
                $this->treeOptions[] = "<option $selectOption $disable value ='" . $value["id"] . "'>". $paddingLeft . $value["title"] . "</option>";
                $level = $level + 1;
                $this->printCategories($value["id"], $level);
                $level = $level - 1;
            }
        }
    }

    protected function getForeignKeyOptions()
    {
        $foreignValueField = $this->getForeignValueFields();

        $db = DB::table($this->getAttribute('foreign_table'))
            ->select($foreignValueField)
            ->addSelect($this->getAttribute('foreign_key_field'));
        $additionalWheres = $this->getAttribute('additional_where');
        if ($additionalWheres) {
            foreach ($additionalWheres as $key => $opt) {
                if (trim($opt['sign']) == "in") {
                    $db->whereIn($key, $opt['value']);
                } elseif (trim($opt['sign']) == "not in") {
                    $db->whereNotIn($key, $opt['value']);
                } else {
                    $db->where($key, $opt['sign'], $opt['value']);
                }
            }
        }
        $orderBy = $this->getAttribute('orderBy');
        if ($orderBy && is_array($orderBy)) {
            foreach ($orderBy as $order) {
                if (isset($order['field']) && isset($order['type'])) {
                    $db->orderBy($order['field'], $order['type']);
                }
            }
        }
        $res = $db->get();

        $options = array();
        $foreignKey = $this->getAttribute('foreign_key_field');

        foreach ($res as $val) {
            $val = (array) $val;
            $resultValArray = array_only($val, $foreignValueField);
            $options[$val[$foreignKey]] = implode (" ", $resultValArray);
        }

        return $options;
    }

    public function getListValueDefinitionPopup($row)
    {
        if ($this->hasCustomHandlerMethod('onGetListValue')) {
            $res = $this->handler->onGetListValue($this, $row);
            if ($res)  return $res;
        }

        $nameField = $this->getFieldName();

        if (!$row->$nameField) return $this->getAttribute('null_caption');

        $result = (array) DB::table($this->getAttribute('foreign_table'))
            ->select($this->getForeignValueFields())
            ->where($this->getAttribute('foreign_key_field'), $row->$nameField)->first();

        return implode (" ", $result);
    }

    public function getAjaxSearchResult($query, $limit, $page)
    {
        if ($this->hasCustomHandlerMethod('onGetAjaxSearchResult')) {
            $res = $this->handler->onGetAjaxSearchResult($this, $query, $limit, $page);
            if ($res) return $res;
        }

        $foreignValueFields = $this->getForeignValueFields();

        $results =  DB::table($this->getAttribute('foreign_table'))
            ->select($foreignValueFields)
            ->addSelect($this->getAttribute ('foreign_key_field'))
            ->where(function ($queryRes) use ($foreignValueFields, $query)
             {
                foreach ($foreignValueFields as $field) {
                   $queryRes->orWhere($field, 'like', '%'. $query .'%');
                }
             })
            ->take($limit)
            ->skip(($limit * $page) - $limit);

        $additionalWheres = $this->getAttribute('additional_where');

        if ($additionalWheres) {
            foreach ($additionalWheres as $field => $where) {
                $results = $where['sign'] == 'in'
                    ? $results->whereIn($field, $where['value'])
                    : $results->where($field, $where['sign'], $where['value']);
            }
        }

        $results = $this->replaceObjectToArray($results->get());

        $collection = collect($results)->map(function ($result) use ($foreignValueFields) {
            $result = (array) $result;

            $resultName = array_only($result, $foreignValueFields);

            return array(
                'id'   => $result[$this->getAttribute('foreign_key_field')],
                'name' => implode(" ", $resultName),
            );
        });

        return array(
            'results' => $collection,
            'message' => ''
        );
    }

    private function replaceObjectToArray($params)
    {
        return json_decode(json_encode($params), true);
    }

    private function getForeignValueFields()
    {
        return explode (" ", $this->getAttribute('foreign_value_field'));
    }

}
