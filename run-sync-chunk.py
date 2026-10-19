#!/usr/bin/env python3
"""Run one Event Sync chunk on ott-dev (QA helper).

Usage: python3 run-sync-chunk.py [chunk_size]

Invokes the private run_event_sync() via ReflectionMethod, exactly like the
AJAX path does, and prints a one-line compact summary. Exit code 0 on HTTP
success regardless of run state; check the JSON to decide whether to repeat.
"""
import importlib.util
import json
import os
import sys

_spec = importlib.util.spec_from_file_location(
    "ott_dev", os.path.join(os.path.dirname(os.path.abspath(__file__)), "ott-dev.py")
)
ott_dev = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(ott_dev)


def main():
    chunk = sys.argv[1] if len(sys.argv) > 1 else "25"
    code = """
$m = new ReflectionMethod("EventKoi_Tickets_Importer", "run_event_sync");
$m->setAccessible(true);
$r = $m->invoke(EventKoi_Tickets_Importer::instance(), %d, true, []);
$errs = [];
foreach ($r["results"] as $res) {
  if (($res["status"] ?? "") === "error") {
    $errs[] = $res["tec_id"] . ":" . mb_substr($res["error"], 0, 60);
  }
}
return [
  "processed" => $r["processed"],
  "imported" => $r["tally"]["imported"],
  "errors" => $r["tally"]["errors"],
  "skipped" => $r["tally"]["skipped"],
  "tickets" => $r["tally"]["tickets"],
  "cost_tickets" => $r["tally"]["cost_tickets"],
  "tc_attendees" => $r["tally"]["tc_attendees"],
  "rsvp_rows" => $r["tally"]["rsvp_rows"],
  "rsvp_merged" => $r["tally"]["rsvp_merged"],
  "remaining" => $r["remaining"],
  "failed_total" => $r["failed_total"],
  "done" => $r["done"],
  "err_sample" => array_slice($errs, 0, 3),
];
""" % int(chunk)
    status, payload = ott_dev.run_ability(
        "novamira/execute-php", {"code": code}, timeout=110
    )
    if status != 200 or not payload.get("success"):
        print(json.dumps({"http": status, "payload": payload}))
        return 1
    print(json.dumps(payload["return_value"], ensure_ascii=False))
    return 0


if __name__ == "__main__":
    sys.exit(main())
