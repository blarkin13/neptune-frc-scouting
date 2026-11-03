"""Wake a sleeping computer from Neptune → My computers (Wake-on-LAN).

Each helper reports its network card address and local IP when it checks in. When the owner presses Wake on a
sleeping computer, Neptune asks another helper on the same home network (same public address) to broadcast a
magic packet for it. The sleeping computer's network card must allow wake by magic packet.
A computer that never reported its address (an older helper) is woken through the helper's list of devices recently
seen on the network: a magic packet only wakes the sleeping machine whose card it names, so the rest ignore it.
"""
import re
import socket
import uuid


def local_network():
    """{'mac', 'ip'} of the card this computer uses for the internet, or {} when unknown."""
    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
            s.connect(('192.0.2.1', 9))   # no packet is sent; this only picks the outgoing interface
            ip = s.getsockname()[0]
    except OSError:
        return {}
    node = uuid.getnode()
    if node >> 40 & 1:   # multicast bit set: Python made up a random number, not a real card address
        return {}
    mac = ':'.join('%02x' % (node >> s & 0xff) for s in range(40, -1, -8))
    return {'mac': mac, 'ip': ip}


def magic_packet(mac):
    if not re.fullmatch(r'[0-9a-f]{2}(:[0-9a-f]{2}){5}', mac or ''):
        raise ValueError('Invalid network card address')
    return b'\xff'*6 + bytes.fromhex(mac.replace(':', ''))*16


def send(target):
    """Broadcast the magic packet for target {'mac', 'ip'} on this network. Returns how many sends went out."""
    packet = magic_packet(target.get('mac'))
    places = ['255.255.255.255']
    ip = target.get('ip') or ''
    if re.fullmatch(r'(\d{1,3}\.){3}\d{1,3}', ip):
        places.append(ip.rsplit('.', 1)[0]+'.255')   # most home networks are /24
    sent = 0
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as s:
        s.setsockopt(socket.SOL_SOCKET, socket.SO_BROADCAST, 1)
        for place in places:
            for port in (9, 7):
                try:
                    s.sendto(packet, (place, port)); sent += 1
                except OSError:
                    pass
    return sent


def neighbors(table='/proc/net/arp'):
    """Card addresses of devices this computer has recently seen on its network (Linux)."""
    out = []
    try:
        lines = open(table).read().splitlines()[1:]
    except OSError:
        return out
    for line in lines:
        cols = line.split()
        if len(cols) >= 4 and re.fullmatch(r'[0-9a-f]{2}(:[0-9a-f]{2}){5}', cols[3]) and cols[3] != '00:00:00:00:00:00':
            out.append({'mac': cols[3], 'ip': cols[0]})
    return out


def wake(target):
    """Wake one computer, or every recently seen device when Neptune does not know its address."""
    if target.get('neighbors'):
        return sum(send(n) for n in neighbors()[:64])
    return send(target)
