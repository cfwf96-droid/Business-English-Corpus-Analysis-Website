#!/usr/bin/env python3
import sys, jieba.posseg as pseg
from gpflib import GPF

def main():
    if len(sys.argv) < 2:
        return
    raw = sys.argv[1].strip()
    if not raw:
        return
    tokens = [f"{w}/{f[0]}" for w, f in pseg.cut(raw)]
    sent   = " ".join(tokens)
    gpf  = GPF()
    tree = gpf.Parse(sent, Structure="Tree")
    print(tree)

if __name__ == '__main__':
    main()