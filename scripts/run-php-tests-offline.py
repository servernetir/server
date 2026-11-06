#!/usr/bin/env python3
"""Run PHP tests with kernel-enforced denial of network sockets (Linux).

Laravel Http::fake covers Laravel's client, not every possible native SDK.
This filter also covers child processes and refuses to run without isolation.
Use only in an isolated development checkout, never the production app.
"""

import argparse
import ctypes
import errno
import os
import socket
import sys


def disable_network():
    if not sys.platform.startswith("linux"):
        raise RuntimeError("Linux with libseccomp is required; no unisolated fallback.")
    libc = ctypes.CDLL(None, use_errno=True)
    if libc.prctl(38, 1, 0, 0, 0) != 0:  # PR_SET_NO_NEW_PRIVS
        raise RuntimeError("Could not enable no-new-privileges.")
    seccomp = ctypes.CDLL("libseccomp.so.2", use_errno=True)
    seccomp.seccomp_init.argtypes = [ctypes.c_uint32]
    seccomp.seccomp_init.restype = ctypes.c_void_p
    seccomp.seccomp_syscall_resolve_name.argtypes = [ctypes.c_char_p]
    seccomp.seccomp_syscall_resolve_name.restype = ctypes.c_int
    seccomp.seccomp_rule_add.argtypes = [ctypes.c_void_p, ctypes.c_uint32, ctypes.c_int, ctypes.c_uint]
    seccomp.seccomp_rule_add.restype = ctypes.c_int
    seccomp.seccomp_load.argtypes = [ctypes.c_void_p]
    seccomp.seccomp_load.restype = ctypes.c_int
    seccomp.seccomp_release.argtypes = [ctypes.c_void_p]
    context = seccomp.seccomp_init(0x7FFF0000)  # SCMP_ACT_ALLOW
    if not context:
        raise RuntimeError("Could not create network-denial filter.")
    try:
        for name in (b"socket", b"socketpair", b"connect", b"sendto", b"sendmsg", b"sendmmsg", b"socketcall", b"io_uring_setup"):
            number = seccomp.seccomp_syscall_resolve_name(name)
            if number >= 0 and seccomp.seccomp_rule_add(context, 0x00050000 | errno.EPERM, number, 0) != 0:
                raise RuntimeError("Could not install network-denial rule.")
        if seccomp.seccomp_load(context) != 0:
            raise RuntimeError("Could not activate network-denial filter.")
    finally:
        seccomp.seccomp_release(context)
    for family in (socket.AF_INET, socket.AF_INET6):
        try:
            probe = socket.socket(family, socket.SOCK_STREAM)
        except OSError as error:
            if error.errno == errno.EPERM:
                continue
            raise
        else:
            probe.close()
            raise RuntimeError("Network-denial verification failed.")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php")
    parser.add_argument("--ini")
    parser.add_argument("--verify-only", action="store_true")
    parser.add_argument("tests", nargs="*")
    args = parser.parse_args()
    if not args.verify_only and (not args.php or not os.path.isfile("vendor/bin/phpunit")):
        parser.error("Run from an isolated website/ checkout and supply --php.")
    disable_network()
    print("Verified: IPv4/IPv6 sockets denied; child processes inherit the filter.", file=sys.stderr, flush=True)
    if args.verify_only:
        return
    command = [args.php]
    if args.ini:
        command += ["-c", args.ini]
    command += ["vendor/bin/phpunit", *args.tests]
    os.execv(args.php, command)


if __name__ == "__main__":
    main()
