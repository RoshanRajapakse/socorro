# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.

import json
import sys

from configman import Namespace
from configman.converters import class_converter
from fillmore.libsentry import set_up_sentry
from fillmore.scrubber import Scrubber, SCRUB_RULES_DEFAULT
import markus

from socorro.app.socorro_app import App
from socorro.lib.libdockerflow import get_release_name
from socorro.schemas import TELEMETRY_SOCORRO_CRASH_SCHEMA


METRICS = markus.get_metrics("processor")


def count_sentry_scrub_error(msg):
    METRICS.incr("sentry_scrub_error", 1)


class UploadTelemetrySchema(App):
    """Uploads schema to S3 bucket for Telemetry

    We always send a copy of the crash (mainly processed crash) to a S3 bucket
    meant for Telemetry to ingest. When they ingest, they need a copy of our
    telemetry_socorro_crash.json JSON Schema file.

    They use that not to understand the JSON we store but the underlying
    structure (types, nesting etc.) necessary for storing it in .parquet files
    in S3.

    To get a copy of the telemetry_socorro_crash.json they can take it from the git
    repository but that's fragile since it depends on github.com always being available.

    By uploading it to S3 not only do we bet on S3 being more read-reliable
    that github.com's server but by being in S3 AND unavailable that means the
    whole ingestion process has to halt/pause anyway.

    """

    app_name = "upload-telemetry-schema"
    app_version = "0.1"
    app_description = "Uploads JSON schema to S3 bucket for Telemetry"
    metadata = ""

    required_config = Namespace()
    required_config.namespace("telemetry")
    required_config.telemetry.add_option(
        "resource_class",
        default="socorro.external.boto.connection_context.S3Connection",
        doc="fully qualified dotted Python classname to handle Boto connections",
        from_string_converter=class_converter,
        reference_value_from="resource.boto",
    )
    required_config.telemetry.add_option(
        "json_filename",
        default="telemetry_socorro_crash.json",
        doc="Name of the file/key we're going to upload to",
    )

    @classmethod
    def configure_sentry(cls, basedir, host_id, sentry_dsn):
        release = get_release_name(basedir)
        scrubber = Scrubber(
            rules=SCRUB_RULES_DEFAULT,
            error_handler=count_sentry_scrub_error,
        )
        set_up_sentry(
            sentry_dsn=sentry_dsn,
            release=release,
            host_id=host_id,
            # Disable frame-local variables
            with_locals=False,
            # Scrub sensitive data
            before_send=scrubber,
        )

    def main(self):
        path = self.config.telemetry.json_filename
        conn = self.config.telemetry.resource_class(self.config.telemetry)
        data = json.dumps(TELEMETRY_SOCORRO_CRASH_SCHEMA, indent=2, sort_keys=True)
        conn.save_file(path=path, data=data.encode("utf-8"))
        self.logger.info("Success: Schema uploaded!")
        return 0


if __name__ == "__main__":
    sys.exit(UploadTelemetrySchema.run())
