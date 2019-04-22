<?php

namespace DigitalStar\vk_api;

/**
 * Class LongPoll
 * @package DigitalStar\vk_api
 */
class LongPoll extends vk_api
{
    /**
     * @var
     */
    private $vk;
    /**
     * @var
     */
    private $group_id;
    /**
     * @var
     */
    private $user_id;
    /**
     * @var
     */
    private $key;
    /**
     * @var
     */
    private $server;
    /**
     * @var
     */
    private $ts;

    /**
     * LongPoll constructor.
     * @param $vk
     */
    public function __construct($vk)
    {
        parent::setAllDataclass($vk->copyAllDataclass());
        $this->vk = $vk;
        if ($vk->auth_type == 'user') {
            $this->user_id = $vk->userInfo()['id'];
        } else {
            $this->group_id = $this->vk->request('groups.getById', [])[0]['id'];
            $this->vk->request('groups.setLongPollSettings', [
                'group_id' => $this->group_id,
                'enabled' => 1,
                'api_version' => $this->vk->version,
                'message_new' => 1
            ]);
        }
        $this->getLongPollServer();
    }

    /**
     *
     */
    public function getLongPollServer()
    {
        if ($this->vk->auth_type == 'user')
            $data = $this->vk->request('messages.getLongPollServer', ['need_pts' => 1, 'lp_version' => 3]);
        else
            $data = $this->vk->request('groups.getLongPollServer', ['group_id' => $this->group_id]);
        list($this->key, $this->server, $this->ts) = [$data['key'], $data['server'], $data['ts']];
    }

    /**
     * @param $anon
     */
    public function listen($anon)
    {
        while ($data = $this->processingData())
            foreach ($data->updates as $event) {
                $this->data = $event;
                $anon($event);
            }
    }

    /**
     * @return mixed
     */
    public function processingData()
    {
        $data = $this->getData();
        if (isset($data->failed)) {
            if ($data->failed == 1)
                $this->ts = $data->ts;
            else {
                $this->getLongPollServer();
                $this->getData();
            }
        }
        $this->ts = $data->ts;
        return $data;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        $str = "{$this->server}?act=a_check&key={$this->key}&ts={$this->ts}&wait=25";
        $data = ($this->vk->auth_type == 'user') ? file_get_contents('https://' . $str . '&mode=32&version=3') : file_get_contents($str);
        return json_decode($data);
    }

    /**
     * @param $type
     * @param $anon
     */
    public function on($type, $anon)
    {
        $summands = [];
        $data = json_decode(json_encode($this->data), true);
        switch ($type) {
            case 'new_message':
                {
                    if ($data[0] == 4) {
                        foreach ([1, 2, 4, 8, 16, 32, 64, 128, 256, 512, 65536] as $key) {
                            if ($data[2] & $key)
                                $summands[] = $key;
                        }
                        if (!in_array(2, $summands)) { //только входящие сообщения
                            $this->data = [];
                            $this->data['object']['peer_id'] = $data[3];
                            $this->data['object']['text'] = $data[5];
                            $this->data = json_decode(json_encode($this->data));
                            $anon($data);
                        }
                    }
                    break;
                }
        }
    }

    /**
     * @param $selectors
     * @param mixed ...$args
     * @throws VkApiException
     */
    public function initVars($selectors, &...$args)
    {
        $data = $this->data;

        if (isset($data->object->payload))
            $data->object->payload = json_decode($data->object->payload, true);

        $init = [
            'id' => isset($data->object->peer_id) ? $data->object->peer_id : null,
            'user_id' => isset($data->object->from_id) ? $data->object->from_id : null,
            'message' => isset($data->object->text) ? $data->object->text : null,
            'payload' => isset($data->object->payload) ? $data->object->payload : null,
            'type' => isset($this->data->type) ? $this->data->type : null,
            'all' => $data,
        ];
        $selectors = explode(',', $selectors);
        if (count($selectors) != count($args))
            throw new VkApiException('Разное количество аргументов и переменных при инициализации');
        foreach ($selectors as $key => $val)
            $args[$key] = $init[trim($val)];
    }
}