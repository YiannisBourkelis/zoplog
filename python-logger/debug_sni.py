#!/usr/bin/env python3

import scapy.all as scapy
from scapy.layers.http import HTTPRequest
from scapy.packet import bind_layers
from scapy.layers.inet import TCP
import sys
import os

# Add the logger directory to Python path
sys.path.insert(0, os.path.dirname(__file__))

# Import the SNI extraction function
from logger import extract_tls_sni

# Ensure HTTP dissector works on all ports
bind_layers(TCP, HTTPRequest)

def debug_packet_handler(packet):
    """Debug packet handler to analyze HTTP/HTTPS traffic on any port"""
    if packet.haslayer(scapy.TCP):
        dst_port = packet[scapy.TCP].dport
        src_port = packet[scapy.TCP].sport

        src_ip, dst_ip = None, None
        if packet.haslayer(scapy.IP):
            src_ip, dst_ip = packet[scapy.IP].src, packet[scapy.IP].dst
        elif packet.haslayer(scapy.IPv6):
            src_ip, dst_ip = packet[scapy.IPv6].src, packet[scapy.IPv6].dst

        print(f"TCP Packet: {src_ip}:{src_port} -> {dst_ip}:{dst_port}")
        print(f"  Payload size: {len(bytes(packet[scapy.Raw].load)) if packet.haslayer(scapy.Raw) else 0}")

        # Check for HTTP
        if packet.haslayer(HTTPRequest):
            http_request = packet[HTTPRequest]
            method = http_request.Method.decode() if http_request.Method else "UNKNOWN"
            host = http_request.Host.decode() if http_request.Host else "UNKNOWN"
            path = http_request.Path.decode() if http_request.Path else "/"
            print(f"  HTTP Request: {method} {host}{path}")
        else:
            # Check for HTTPS (TLS with possible SNI)
            hostname = extract_tls_sni(packet)
            if hostname:
                print(f"  HTTPS SNI: {hostname}")
            else:
                print(f"  Non-HTTP/HTTPS TCP traffic")

        print("-" * 60)

def main():
    if len(sys.argv) != 2:
        print("Usage: python3 debug_sni.py <interface>")
        print("Example: python3 debug_sni.py br-zoplog")
        print("This will capture HTTP/HTTPS traffic on ANY port")
        sys.exit(1)

    interface = sys.argv[1]
    print(f"Debugging HTTP/HTTPS traffic on ANY port on {interface}...")
    print("Press Ctrl+C to stop")
    print("=" * 60)

    try:
        # Capture all TCP traffic (not just port 443)
        scapy.sniff(iface=interface, filter="tcp", prn=debug_packet_handler, store=False, count=50)
    except KeyboardInterrupt:
        print("\nDebugging stopped")
    except Exception as e:
        print(f"Error: {e}")

if __name__ == "__main__":
    main()
