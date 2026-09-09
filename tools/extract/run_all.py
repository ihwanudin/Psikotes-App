import argparse
from pathlib import Path
from . import extract_aspect_sources, extract_dass, extract_ist, extract_kraepelin, extract_papi, extract_reporting, extract_rmib


def main():
    p=argparse.ArgumentParser(); p.add_argument("source_root",type=Path); args=p.parse_args()
    for module in (extract_ist,extract_papi,extract_rmib,extract_kraepelin,extract_dass,extract_reporting,extract_aspect_sources): module.extract(args.source_root)


if __name__ == "__main__": main()
