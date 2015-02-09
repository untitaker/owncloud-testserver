# -*- coding: utf-8 -*-

import os
import subprocess
import time

import lxml.html

import pytest

import requests

from vdirsyncer.utils import request


owncloud_repo = os.path.dirname(__file__)
php_sh = os.path.abspath(os.path.join(owncloud_repo, 'php.sh'))
base = 'http://127.0.0.1:8080'
username, password = ('asdf', 'asdf')


def wait():
    for i in range(5):
        try:
            requests.get(base + '/')
        except Exception as e:
            # Don't know exact exception class, don't care.
            # Also, https://github.com/kennethreitz/requests/issues/2192
            if 'connection refused' not in str(e).lower():
                raise
            time.sleep(2 ** i)
        else:
            return True
    return False


class ServerMixin(object):
    storage_class = None
    wsgi_teardown = None

    @pytest.fixture(autouse=True)
    def setup(self, monkeypatch, xprocess):
        def preparefunc(cwd):
            return wait, ['sh', php_sh]

        xprocess.ensure('owncloud_server', preparefunc)
        subprocess.check_call([os.path.join(owncloud_repo, 'reset.sh')])

    @pytest.fixture
    def get_storage_args(self):
        def inner(collection='test'):
            fileext = self.storage_class.fileext
            if fileext == '.vcf':
                dav_path = ('/remote.php/carddav/addressbooks/{}/'
                            .format(username))
            elif fileext == '.ics':
                dav_path = ('/remote.php/caldav/calendars/{}/'
                            .format(username))
            else:
                raise RuntimeError(fileext)

            rv = {'url': base + dav_path, 'collection': collection,
                  'username': username, 'password': password,
                  'unsafe_href_chars': ''}

            if collection is not None:
                return self.storage_class.create_collection(**rv)
            else:
                return rv


        return inner
