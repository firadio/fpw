<?php

class RFC1035 {

    /*
    * 参考文档
    * https://datatracker.ietf.org/doc/html/rfc1035
    */

    public function __construct() {
    }

    private function getDnsTypeValue($type) {
        $types = array(
            'A'     => 0x0001,
            'NS'    => 0x0002,
            'CNAME' => 0x0005,
            'SOA'   => 0x0006,
            'PTR'   => 0x000c,
            'MX'    => 0x000f,
            'TXT'   => 0x0010,
            'AAAA'  => 0x001c,
        );

        if (isset($types[$type])) {
            return $types[$type];
        }

        // 默认返回 A 记录类型
        return $types['A'];
    }

    public function constructDnsQueryPacket($domain, $type = 'A', $id = 0) {
        $packet = pack('n', $id); // 2-byte ID
        $packet .= pack('n', 0x0100); // 2-byte flags (standard query)
        $packet .= pack('nnnn', 1, 0, 0, 0); // 4-byte counts (QDCount, ANCount, NSCount, ARCount)

        $domainParts = explode('.', $domain);
        foreach ($domainParts as $part) {
            $packet .= pack('C', strlen($part)); // Length of each part
            $packet .= $part; // Part of the domain
        }

        $packet .= pack('C', 0); // Terminating 0-length octet

        $packet .= pack('n', $this->getDnsTypeValue($type)); // 2-byte QTYPE
        $packet .= pack('n', 1); // 2-byte QCLASS (IN)

        return $packet;
    }

    /*
     * 解析DNS响应的结果
     */
    public function parseDnsResponsePacket($packet) {
        $response = array();

        $header = substr($packet, 0, 12);
        $response['ID'] = unpack('n', substr($header, 0, 2))[1]; // 2-byte ID
        $flags = unpack('n', substr($header, 2, 2))[1]; // 2-byte flags
        $response['QR'] = ($flags & 0x8000) >> 15; // QR bit
        $response['AA'] = ($flags & 0x0400) >> 10; // AA bit
        $response['RA'] = ($flags & 0x0080) >> 7; // RA bit
        $response['RCODE'] = $flags & 0x000F; // RCODE bits

        $qCount = unpack('n', substr($header, 4, 2))[1]; // 2-byte QDCount
        $response['QDCOUNT'] = $qCount;

        $aCount = unpack('n', substr($header, 6, 2))[1]; // 2-byte ANCount
        $response['ANCOUNT'] = $aCount;

        $nsCount = unpack('n', substr($header, 8, 2))[1]; // 2-byte NSCount
        $response['NSCOUNT'] = $nsCount;

        $arCount = unpack('n', substr($header, 10, 2))[1]; // 2-byte ARCount
        $response['ARCOUNT'] = $arCount;

        $offset = 12;

        // Parse question section
        for ($i = 0; $i < $qCount; $i++) {
            $response['questions'][] = $this->parseDnsDomain($packet, $offset);
            $offset += 4; // 4 bytes for QTYPE and QCLASS
        }

        // Parse answer section
        for ($i = 0; $i < $aCount; $i++) {
            $response['answers'][] = $this->parseDnsResourceRecord($packet, $offset);
        }

        // Parse authority section
        for ($i = 0; $i < $nsCount; $i++) {
            $response['authorities'][] = $this->parseDnsResourceRecord($packet, $offset);
        }

        // Parse additional section
        for ($i = 0; $i < $arCount; $i++) {
            $response['additionals'][] = $this->parseDnsResourceRecord($packet, $offset);
        }

        return $response;
    }

    private function parseDnsDomain($packet, &$offset) {
        $domain = '';

        while (true) {

            $length = ord($packet[$offset]);

            if ($length === 0) {
                $offset++;
                break;
            }

            $isPointer = ($length & 0xC0) === 0xC0;

            if ($isPointer) {
                $pointerOffset = unpack('n', substr($packet, $offset, 2))[1] & 0x3FFF;
                $subDomain = $this->parseDnsDomain($packet, $pointerOffset);
                $domain .= $subDomain['domain'];
                $offset += 2;
                break;
            }

            $offset++;
            $subDomain = substr($packet, $offset, $length);
            $domain .= $subDomain . '.';
            $offset += $length;
        }

        return array(
            'domain' => $domain,
            'offset' => $offset
        );
    }

    private function parseDnsResourceRecord($packet, &$offset) {
        $record = array();

        $name = $this->parseDnsDomain($packet, $offset);
        $record['NAME'] = $name['domain'];

        $record['TYPE'] = unpack('n', substr($packet, $offset, 2))[1];
        $offset += 2;

        $record['CLASS'] = unpack('n', substr($packet, $offset, 2))[1];
        $offset += 2;

        $record['TTL'] = unpack('N', substr($packet, $offset, 4))[1];
        $offset += 4;

        $rdLength = unpack('n', substr($packet, $offset, 2))[1];
        $offset += 2;

        $record['RDLENGTH'] = $rdLength;

        $record['RDATA'] = substr($packet, $offset, $rdLength);
        $record['RDATA'] = $this->parseDnsRecordData($record['TYPE'], $record['RDATA']);
        $offset += $rdLength;

        return $record;
    }

    private function parseDnsRecordData($iType, $rdata) {
        if ($iType === 1) {
            // Convert binary data to IPv4 address string
            return inet_ntop($rdata);
        }
        if ($iType === 16) {
            $txtData = substr($rdata, 1); // Skip the length byte at the beginning
            return $txtData;
            // Split multiple text strings if present
            $txtStrings = explode("\0", $txtData);
            return $txtStrings;
        }
        return $rdata;
    }

}
