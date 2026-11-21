#!/usr/bin/env python3
"""Run a command under an inherited filter blocking outbound network syscalls."""

import ctypes
import ctypes.util
import errno
import os
import socket
import sys


def main():
    command = sys.argv[1:]
    if command[:1] == ["--"]:
        command = command[1:]
    if not command:
        raise RuntimeError("Usage: run-offline.py -- COMMAND [ARG ...]")
    library = ctypes.util.find_library("seccomp")
    if not library:
        raise RuntimeError("libseccomp is required for network isolation")
    seccomp = ctypes.CDLL(library, use_errno=True)
    seccomp.seccomp_init.argtypes = [ctypes.c_uint32]
    seccomp.seccomp_init.restype = ctypes.c_void_p
    seccomp.seccomp_syscall_resolve_name.argtypes = [ctypes.c_char_p]
    seccomp.seccomp_syscall_resolve_name.restype = ctypes.c_int
    seccomp.seccomp_rule_add.argtypes = [ctypes.c_void_p, ctypes.c_uint32, ctypes.c_int, ctypes.c_uint]
    seccomp.seccomp_rule_add.restype = ctypes.c_int
    seccomp.seccomp_load.argtypes = [ctypes.c_void_p]
    seccomp.seccomp_load.restype = ctypes.c_int
    seccomp.seccomp_release.argtypes = [ctypes.c_void_p]
    class ArgumentComparison(ctypes.Structure):
        _fields_ = [("arg", ctypes.c_uint), ("op", ctypes.c_uint),
                    ("datum_a", ctypes.c_uint64), ("datum_b", ctypes.c_uint64)]

    seccomp.seccomp_rule_add_array.argtypes = [ctypes.c_void_p, ctypes.c_uint32, ctypes.c_int,
                                              ctypes.c_uint, ctypes.POINTER(ArgumentComparison)]
    seccomp.seccomp_rule_add_array.restype = ctypes.c_int
    context = seccomp.seccomp_init(0x7FFF0000)
    if not context:
        raise RuntimeError("Could not allocate the network isolation filter")
    try:
        # Keep local IPC available for asyncio subprocesses and MCP stdio tests.
        comparison = ArgumentComparison(0, 1, socket.AF_UNIX, 0)  # SCMP_CMP_NE
        number = seccomp.seccomp_syscall_resolve_name(b"socket")
        if number < 0 or seccomp.seccomp_rule_add_array(context, 0x00050000 | errno.EACCES,
                                                       number, 1, ctypes.byref(comparison)) != 0:
            raise RuntimeError("Could not deny network socket creation")
        for name in [b"io_uring_setup"]:
            number = seccomp.seccomp_syscall_resolve_name(name)
            if number < 0 or seccomp.seccomp_rule_add(context, 0x00050000 | errno.EACCES, number, 0) != 0:
                raise RuntimeError("Could not configure network isolation")
        if seccomp.seccomp_load(context) != 0:
            raise RuntimeError("Could not enable network isolation")
    finally:
        seccomp.seccomp_release(context)
    os.execvp(command[0], command)


if __name__ == "__main__":
    try:
        main()
    except (OSError, RuntimeError) as error:
        print(f"Offline runner: {error}", file=sys.stderr)
        sys.exit(2)
