# This Source Code Form is subject to the terms of the Mozilla Public
# License, v. 2.0. If a copy of the MPL was not distributed with this
# file, You can obtain one at https://mozilla.org/MPL/2.0/.

from click.testing import CliRunner

from socorro.scripts.es import es_group


def test_it_runs():
    """Test whether the module loads and spits out help."""
    runner = CliRunner()
    result = runner.invoke(es_group, ["--help"])
    assert result.exit_code == 0
