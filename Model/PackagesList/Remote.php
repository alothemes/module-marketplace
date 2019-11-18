<?php

namespace Swissup\Marketplace\Model\PackagesList;

class Remote extends AbstractList
{
    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    private $request;

    /**
     * @var \Swissup\Marketplace\Model\Session
     */
    private $session;

    /**
     * @var \Swissup\Marketplace\Model\ChannelRepository
     */
    private $channelRepository;

    /**
     * @var string
     */
    private $channelId;

    /**
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Swissup\Marketplace\Model\Session $session
     * @param \Swissup\Marketplace\Model\ChannelRepository $channelRepository
     */
    public function __construct(
        \Magento\Framework\App\RequestInterface $request,
        \Swissup\Marketplace\Model\Session $session,
        \Swissup\Marketplace\Model\ChannelRepository $channelRepository
    ) {
        $this->request = $request;
        $this->session = $session;
        $this->channelRepository = $channelRepository;
    }

    /**
     * @param mixed $id
     * @return $this
     */
    public function setChannelId($id)
    {
        $this->channelId = $id;

        return $this;
    }

    /**
     * @param string|null $id
     * @return $this
     */
    public function getChannelId()
    {
        return $this->channelId ?? $this->session->getChannelId();
    }

    /**
     * @return array
     */
    public function getList()
    {
        if ($this->isLoaded()) {
            return $this->data;
        }

        try {
            $channel = $this->channelRepository->getById($this->getChannelId(), true);

            foreach ($channel->getPackages() as $id => $packageData) {
                $versions = array_keys($packageData);
                $latestVersion = array_reduce($versions, function ($carry, $item) {
                    if (version_compare($carry, $item) === -1) {
                        $carry = $item;
                    }
                    return $carry;
                });

                $this->data[$id] = $this->extractPackageData($packageData[$latestVersion]);
                foreach ($packageData as $version => $data) {
                    $this->data[$id]['versions'][$version] = $this->extractPackageData($data);
                }
            }
        } catch (\Exception $e) {
            $this->data = [];
        }

        $this->isLoaded(true);

        return $this->data;
    }
}
